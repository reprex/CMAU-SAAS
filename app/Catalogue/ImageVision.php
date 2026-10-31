<?php

namespace App\Catalogue;

use App\Models\ImageCheck;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Claude looks at a product's unsure images (docs/images.md) and says, for
 * each, whether it shows the product in the hero's colour (other style) or in
 * another colour (other colour, and which), and what it shows (angle, detail).
 * It also checks the Promodata hero and colour images it is given as
 * references. Results are stored per image URL; ImageTagger combines them
 * with the file-name rules.
 */
class ImageVision
{
    public const SHOWS = ['whole_product', 'angle', 'detail', 'in_use', 'packaging', 'decoration', 'other'];

    public function enabled(): bool
    {
        return config('images.check.enabled') && filled(config('search.anthropic.key'));
    }

    /** Image URLs of this product that Claude hasn't looked at yet. */
    public function unchecked(Product $product): array
    {
        $tags = app(ImageTagger::class)->tags($product);
        // The hero and colour images first (usually right, not always), so Claude sees them before the rest.
        $want = $tags->where('auto_role', ImageTagger::HERO_STYLE)->pluck('url')
            ->merge($tags->where('auto_role', ImageTagger::COLOUR)->pluck('url'))
            ->merge($tags->whereIn('auto_role', [ImageTagger::OTHER_STYLE, ImageTagger::OTHER_COLOUR])->pluck('url'))->unique()->values();
        $done = ImageCheck::query()->whereIn('url_hash', $want->map(fn ($u) => ImageCheck::hashOf($u)))->pluck('url_hash')->all();

        return $want->reject(fn ($u) => in_array(ImageCheck::hashOf($u), $done, true))->values()->all();
    }

    /** Check one product's unchecked images. Returns how many images were checked. */
    public function check(Product $product): int
    {
        $product->loadMissing(['images', 'colours', 'override']);
        $todo = $this->unchecked($product);
        if ($todo === []) {
            return 0;
        }
        $dayKey = 'image-check:'.now()->format('Ymd');
        if ((int) Cache::get($dayKey, 0) >= (int) config('images.check.images_per_day')) {
            return 0;
        }

        $tags = app(ImageTagger::class)->tags($product)->keyBy('url');
        $hero = $tags->firstWhere('auto_role', ImageTagger::HERO_STYLE)['url'] ?? null;
        $colours = $product->colours->unique('name')->pluck('name')->values()->all();
        $refs = count($colours) <= (int) config('images.check.colour_references')
            ? $tags->where('auto_role', ImageTagger::COLOUR)->mapWithKeys(fn ($t) => [$t['url'] => $t['colour']])->all() : [];

        $done = 0;
        foreach (array_chunk($todo, (int) config('images.check.images_per_request')) as $chunk) {
            try {
                $results = $this->ask($product, $hero, $refs, $colours, $chunk, $tags->all());
            } catch (Throwable $e) {
                if (count($chunk) === 1) {
                    $results = [$chunk[0] => ['tag' => null, 'colour' => null, 'shows' => null, 'confidence' => null, 'note' => 'Could not check: '.mb_substr($e->getMessage(), 0, 120)]];
                } else {
                    // One unreadable image fails the whole request: try them one at a time next run.
                    report($e);

                    continue;
                }
            }
            foreach ($results as $url => $r) {
                ImageCheck::query()->updateOrCreate(['url_hash' => ImageCheck::hashOf($url)], [
                    'url' => $url, 'product_id' => $product->id, 'tag' => $r['tag'], 'colour_name' => $r['colour'],
                    'shows' => $r['shows'], 'confidence' => $r['confidence'], 'note' => $r['note'],
                    'model' => config('images.check.model'), 'checked_at' => now(),
                ]);
                $done++;
            }
            Cache::add($dayKey, 0, now()->endOfDay());
            Cache::increment($dayKey, count($chunk));
        }

        return $done;
    }

    /**
     * @param  array<string, string>  $refs  colour image URL => colour name
     * @param  list<string>  $chunk
     * @return array<string, array{tag:?string, colour:?string, shows:?string, confidence:?float, note:?string}>
     */
    private function ask(Product $product, ?string $hero, array $refs, array $colours, array $chunk, array $tags): array
    {
        $content = [];
        $label = function (string $text) use (&$content) {
            $content[] = ['type' => 'text', 'text' => $text];
        };
        $image = function (string $url) use (&$content) {
            $content[] = ['type' => 'image', 'source' => ['type' => 'url', 'url' => $url]];
        };

        $label("Product: {$product->name} ({$product->code}). Colours: ".($colours ? implode(', ', $colours) : 'none listed').'.');
        if ($hero && ! in_array($hero, $chunk, true)) {
            $label('HERO (reference, the product\'s main picture):');
            $image($hero);
        }
        foreach ($refs as $url => $colour) {
            if (! in_array($url, $chunk, true)) {
                $label("Reference: the {$colour} colour.");
                $image($url);
            }
        }
        foreach ($chunk as $n => $url) {
            $role = $tags[$url]['auto_role'] ?? '';
            $hint = match ($role) {
                ImageTagger::HERO_STYLE => ' Promodata says this is the hero.',
                ImageTagger::COLOUR => ' Promodata says this is the '.($tags[$url]['colour'] ?? '').' colour image.',
                ImageTagger::OTHER_COLOUR => ' The file name suggests '.($tags[$url]['colour'] ?? '').'.',
                default => '',
            };
            $label('IMAGE '.($n + 1).'. File: '.basename((string) parse_url($url, PHP_URL_PATH)).'.'.$hint);
            $image($url);
        }

        $system = 'You sort product photos for an Australian branded merchandise store. '
            .'"other_style": the product in the same colour as the HERO (a different angle, a close-up of a feature, the product in use), or a photo that is not about any one colour. '
            .'"other_colour": the product clearly shown in a different colour from the HERO; give that colour, using a name from the colour list exactly. '
            .'File names are hints only; trust what you see, and say so in the note if they disagree. '
            .'If Promodata says an image is the hero but it is not a clear picture of the whole product, or says it is a colour image but it shows a different colour, say so in the note. '
            .'Reply with JSON only: {"images":[{"n":number,"tag":"other_style"|"other_colour","colour":string|null,"shows":"'.implode('"|"', self::SHOWS).'","confidence":number 0-1,"note":string}]}. '
            .'Notes under 15 words; empty when nothing is wrong.';

        $r = Http::withHeaders(['x-api-key' => config('search.anthropic.key'), 'anthropic-version' => '2023-06-01'])
            ->timeout((int) config('images.check.timeout'))
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => config('images.check.model'),
                'max_tokens' => 120 + 70 * count($chunk),
                'system' => $system,
                'messages' => [['role' => 'user', 'content' => $content]],
            ]);
        if (! $r->successful()) {
            throw new RuntimeException('Claude image check failed: '.$r->status().' '.mb_substr((string) $r->json('error.message'), 0, 80));
        }
        $text = (string) collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode('');
        $json = json_decode(trim(preg_replace('/^```(?:json)?|```$/m', '', $text)), true);
        if (! is_array($json['images'] ?? null)) {
            throw new RuntimeException('Claude image check reply was not JSON');
        }

        $out = [];
        foreach ($json['images'] as $row) {
            $url = $chunk[((int) ($row['n'] ?? 0)) - 1] ?? null;
            if ($url === null) {
                continue;
            }
            $tag = in_array($row['tag'] ?? null, [ImageTagger::OTHER_STYLE, ImageTagger::OTHER_COLOUR], true) ? $row['tag'] : null;
            $colour = in_array($row['colour'] ?? null, $colours, true) ? $row['colour'] : null;
            if ($tag === ImageTagger::OTHER_COLOUR && $colour === null) {
                $tag = null; // a colour we don't sell: leave it to the file-name rules
            }
            $out[$url] = [
                'tag' => $tag,
                'colour' => $colour,
                'shows' => in_array($row['shows'] ?? null, self::SHOWS, true) ? $row['shows'] : null,
                'confidence' => is_numeric($row['confidence'] ?? null) ? max(0, min(1, round((float) $row['confidence'], 2))) : null,
                'note' => mb_substr(strip_tags((string) ($row['note'] ?? '')), 0, 200) ?: null,
            ];
        }

        return $out;
    }
}
