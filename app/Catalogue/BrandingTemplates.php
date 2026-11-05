<?php

namespace App\Catalogue;

use App\Models\BrandingPosition;
use App\Models\BrandingTemplate;
use App\Models\PrintArea;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Supplier branding templates (docs/branding-templates.md). Where a supplier's
 * template exists (Promodata line art, the supplier's URL pattern, or a URL
 * staff add), Claude reads it once with the product's front and back pictures
 * and returns every branding position: its name, view, maximum print size and
 * where it sits on our picture. The front position becomes the product's logo
 * area (source "template", which the sample learning leaves alone), and all
 * positions go to Shopify as custom.brandingpositions.
 */
class BrandingTemplates
{
    public const VIEWS = ['front', 'back', 'side', 'top'];

    /** The shape under a print area: how the logo curves on the mockup. */
    public const SURFACES = ['flat', 'cylinder_v', 'cylinder_h', 'dome'];

    /** Four [x, y] corners in percent (top-left, top-right, bottom-right, bottom-left), or null if not usable. @return list<array{0: float, 1: float}>|null */
    public static function quad(mixed $q): ?array
    {
        if (! is_array($q) || count($q) !== 4) {
            return null;
        }
        $out = [];
        foreach (array_values($q) as $pt) {
            $pt = is_array($pt) ? array_values($pt) : [];
            if (count($pt) < 2 || ! is_numeric($pt[0]) || ! is_numeric($pt[1])) {
                return null;
            }
            $out[] = [round(max(0, min(100, (float) $pt[0])), 2), round(max(0, min(100, (float) $pt[1])), 2)];
        }
        // A usable area: not collapsed, and its corners go round in order (no crossed edges).
        $area = 0.0;
        for ($i = 0; $i < 4; $i++) {
            [$x1, $y1] = $out[$i];
            [$x2, $y2] = $out[($i + 1) % 4];
            $area += $x1 * $y2 - $x2 * $y1;
        }
        $cross = function ($a, $b, $c) {
            return ($b[0] - $a[0]) * ($c[1] - $a[1]) - ($b[1] - $a[1]) * ($c[0] - $a[0]);
        };
        $signs = [];
        for ($i = 0; $i < 4; $i++) {
            $signs[] = $cross($out[$i], $out[($i + 1) % 4], $out[($i + 2) % 4]) > 0;
        }

        return abs($area) / 2 >= 1 && count(array_unique($signs)) === 1 ? $out : null;
    }

    public function __construct(private readonly PrintAreas $areas, private readonly ImageTagger $images) {}

    /** Add the supplier-pattern template for a product if the supplier has one and it exists. */
    public function discover(Product $product): ?BrandingTemplate
    {
        $pattern = (string) $product->supplier?->template_url_pattern;
        if ($pattern === '' || blank($product->code)) {
            return null;
        }
        $url = str_replace(['{code}', '{CODE}', '{appaid}'], [rawurlencode((string) $product->code), rawurlencode(mb_strtoupper((string) $product->code)), (string) $product->id], $pattern);
        if (BrandingTemplate::query()->where('product_id', $product->id)->where('url', $url)->exists()) {
            return null;
        }
        try {
            $ok = Http::timeout(10)->head($url)->successful();
        } catch (Throwable) {
            $ok = false;
        }

        return $ok ? BrandingTemplate::query()->create(['product_id' => $product->id, 'url' => $url, 'source' => 'supplier']) : null;
    }

    /**
     * The picture a print area is placed on: a colour photo, because mockups are only drawn on colour photos
     * (Scott, 2 Nov 2026) and a box placed on the hero or a style photo wouldn't line up on them (5 Nov).
     * Front: a colour's own photo (one that also has a second photo, if any does); back: that colour's
     * other photo, as the product page uses. Suppliers shoot every colour the same way, so one placement
     * serves them all. No colour photo: null, and the template gives positions and sizes only (guide cards).
     */
    public function pictureFor(Product $product, string $view): ?string
    {
        // Each colour's own photo (one that is also the hero counts) and its first other photo, as the product page
        // picks them; only photos checked for example branding and clean or fixable (Scott, 5 Nov 2026).
        $branding = app(PhotoBrandings::class);
        $photos = collect($branding->photos($product))->filter(fn ($p) => $branding->usable($p['url']));
        $fronts = $photos->where('view', 'front')->values();
        if ($fronts->isEmpty()) {
            return null;
        }
        $backs = $photos->where('view', 'back')->keyBy('colour');
        $pick = $fronts->first(fn ($p) => isset($backs[$p['colour']])) ?? $fronts->first();

        return match ($view) {
            'front' => $pick['url'],
            'back' => $backs[$pick['colour']]['url'] ?? null,
            default => null,
        };
    }

    public function read(BrandingTemplate $template): int
    {
        return $this->readMany([$template])[$template->id] ?? 0;
    }

    /**
     * Read templates with Claude, $parallel at a time.
     *
     * @param  iterable<BrandingTemplate>  $templates
     * @return array<int, int> template id => positions found
     */
    public function readMany(iterable $templates, int $parallel = 1): array
    {
        $out = [];
        $templates = collect($templates);
        // Their photos checked for example branding first, in parallel (each photo once).
        rescue(fn () => app(PhotoBrandings::class)->checkMany(Product::query()->with(['images', 'colours', 'override'])
            ->whereIn('id', $templates->pluck('product_id')->unique())->get(), $parallel), [], false);
        // A few at a time, each file loaded only for its own batch (a template PDF can be 20 MB: holding all of a
        // run's files at once ran the server out of memory, 5 Nov 2026). Each is marked "reading" first, so one
        // that stops the reader is set aside as failed by the next run (releaseStuck) instead of blocking the queue.
        foreach ($templates->chunk(max(1, $parallel)) as $batch) {
            $chunk = [];
            foreach ($batch as $t) {
                $t->update(['status' => 'reading', 'message' => null]);
                try {
                    $job = $this->prepare($t);
                } catch (Throwable $e) {
                    $t->update(['status' => 'failed', 'message' => 'Could not prepare: '.mb_substr($e->getMessage(), 0, 400)]);
                    $job = null;
                }
                if ($job === null) {
                    if ($t->fresh()?->status === 'reading') {
                        $t->update(['status' => 'new']); // not read this time (no key, or today's limit): back in the queue
                    }
                    $out[$t->id] = 0;
                } else {
                    $chunk[] = $job;
                }
            }
            if ($chunk === []) {
                continue;
            }
            $send = fn ($r) => $r->withHeaders(['x-api-key' => config('search.anthropic.key'), 'anthropic-version' => '2023-06-01'])
                ->timeout((int) config('images.templates.timeout', 90));
            $responses = count($chunk) === 1
                ? [$this->send($send(Http::getFacadeRoot()), $chunk[0]['body'])]
                : Http::pool(fn ($pool) => array_map(fn ($j) => $send($pool->as((string) $j['template']->id))->post('https://api.anthropic.com/v1/messages', $j['body']), $chunk));
            foreach (array_values($chunk) as $i => $job) {
                $r = $responses[(string) $job['template']->id] ?? $responses[$i] ?? null;
                try {
                    $out[$job['template']->id] = $this->apply($job, $r);
                } catch (Throwable $e) {
                    $job['template']->update(['status' => 'failed', 'message' => mb_substr($e->getMessage(), 0, 500)]);
                    $out[$job['template']->id] = 0;
                }
            }
            unset($chunk, $responses);
        }

        return $out;
    }

    /** Templates left "reading" by a run that stopped (out of memory, timed out): set aside as failed. */
    public static function releaseStuck(int $minutes = 10): int
    {
        return BrandingTemplate::query()->where('status', 'reading')->where('updated_at', '<', now()->subMinutes($minutes))
            ->update(['status' => 'failed', 'message' => 'The reader stopped on this template (probably too large to read); Read again to retry']);
    }

    private function send($request, array $body)
    {
        try {
            return $request->post('https://api.anthropic.com/v1/messages', $body);
        } catch (Throwable $e) {
            return $e;
        }
    }

    /** Is there a Claude key, and room under today's limit? */
    public function canRead(): bool
    {
        return filled(config('search.anthropic.key')) && (int) Cache::get('template-read:'.now()->format('Ymd'), 0) < (int) config('images.templates.per_day', 5000);
    }

    /** The request for one template, or null when it can't be read now (status says why). @return array<string, mixed>|null */
    private function prepare(BrandingTemplate $template): ?array
    {
        $product = Product::query()->with(['supplier', 'images', 'colours', 'override'])->findOrFail($template->product_id);
        $dayKey = 'template-read:'.now()->format('Ymd');
        if ((int) Cache::get($dayKey, 0) >= (int) config('images.templates.per_day', 5000) || blank(config('search.anthropic.key'))) {
            return null;
        }
        rescue(fn () => app(PhotoBrandings::class)->ensureChecked($product), 0, false); // photos checked before they are used
        $front = $this->pictureFor($product, 'front');
        $back = $this->pictureFor($product, 'back');
        $isPdf = (bool) preg_match('/\.pdf(\?|$)/i', (string) ($template->file_path ?: $template->url));
        // An imported template (a private file, docs/branding-templates.md) goes to Claude as the file itself.
        $disk = Storage::disk(TemplateFiles::DISK);
        if ($template->file_path && $disk->exists($template->file_path)) {
            if ((int) $disk->size($template->file_path) > 20 * 1048576 || ! $isPdf) {
                $template->update(['status' => 'stored', 'message' => 'Kept for emails; too large or not a PDF to read for positions']);

                return null;
            }
            $doc = ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode($disk->get($template->file_path))]];
        } else {
            $doc = $isPdf ? ['type' => 'document', 'source' => ['type' => 'url', 'url' => $template->url]] : ['type' => 'image', 'source' => ['type' => 'url', 'url' => $template->url]];
        }
        $dims = collect([$product->dim_1, $product->dim_2, $product->dim_3])->filter(fn ($d) => (float) $d > 0)->map(fn ($d) => rtrim(rtrim(number_format((float) $d, 1, '.', ''), '0'), '.'))->implode(' × ');

        $content = [
            ['type' => 'text', 'text' => "Supplier branding template for {$product->name} (code {$product->code}".($dims ? ", product {$dims} mm" : '').'):'],
            $doc,
        ];
        if ($front) {
            $content[] = ['type' => 'text', 'text' => 'FRONT picture of our product (the box for a front position is on this picture):'];
            $content[] = ['type' => 'image', 'source' => ['type' => 'url', 'url' => $front]];
        }
        if ($back) {
            $content[] = ['type' => 'text', 'text' => 'BACK picture of our product (the box for a back position is on this picture):'];
            $content[] = ['type' => 'image', 'source' => ['type' => 'url', 'url' => $back]];
        }
        Cache::add($dayKey, 0, now()->endOfDay());
        Cache::increment($dayKey);

        return ['template' => $template, 'product' => $product, 'front' => $front, 'back' => $back, 'body' => [
            'model' => config('images.templates.model'), 'max_tokens' => (int) config('images.templates.max_tokens', 1800),
            'system' => self::PROMPT, 'messages' => [['role' => 'user', 'content' => $content]],
        ]];
    }

    /** Reading rules (Scott, 5 Nov 2026: a mockup only where it can be trusted). */
    private const PROMPT = 'You read supplier branding templates for a branded merchandise store, so we can show customers a mockup of their logo. '
        .'Templates are drawn to a stated scale ("100% of actual size", "15% actual size") with every print area marked and sized; some list sizes in a side panel. '
        .'List every branding position once. Name it by where it is on the product in plain words a customer understands (Front centre, Left chest, Barrel, Clip, Lid, Wrap around, Front pocket), '
        .'never by the template\'s letter or number alone ("Position A"). If the same place is offered with several methods, one position with every method. '
        .'For each give: name; view (front, back, side or top: the side of the product the position is on); the methods offered there, each with its own maximum print size in mm '
        .'(a diameter D gives width and height D); the position\'s largest size; and, only if a picture of that view is provided, the print area as a box on that picture, in percent of the picture: '
        .'left and top of the box\'s top-left corner and its width, drawn at the maximum print size to the scale of the product in the picture, where the print area sits on the product. '
        .'Also give the print area\'s four corners on that picture as quad, in percent of the picture ([x,y] each): top-left, top-right, bottom-right, bottom-left as a logo printed upright there would read, '
        .'so a rotated, skewed or perspective surface is followed exactly; and surface, the shape of the product under the print area: "flat"; "cylinder_v", round with the axis running up and down the print '
        .'(a bottle or mug side, a stubby holder); "cylinder_h", round with the axis running across the print (a pen barrel, a lanyard tube); or "dome" (a cap front, a ball). '
        .'confidence is "high" only when the picture shows that side of the product the same way as the template, so the box is where the print really goes; otherwise "low". '
        .'Reply with JSON only: {"positions":[{"name":string,"view":"front"|"back"|"side"|"top","max_width_mm":number|null,"max_height_mm":number|null,'
        .'"methods":[{"name":string,"max_width_mm":number|null,"max_height_mm":number|null}],"box":{"left":n,"top":n,"width":n}|null,"quad":[[x,y],[x,y],[x,y],[x,y]]|null,"surface":"flat"|"cylinder_v"|"cylinder_h"|"dome","confidence":"high"|"low"}]}. '
        .'If it is not a branding template, reply {"positions":[]}. '
        .'Reply with the JSON object only: no explanation, no working, no code fence.';

    /** @param  array<string, mixed>  $job */
    private function apply(array $job, $r): int
    {
        /** @var BrandingTemplate $template */
        ['template' => $template, 'product' => $product, 'front' => $front, 'back' => $back] = $job;
        if ($r instanceof Throwable || $r === null) {
            $template->update(['status' => 'failed', 'message' => 'Claude: '.mb_substr($r instanceof Throwable ? $r->getMessage() : 'no reply', 0, 200)]);

            return 0;
        }
        if (! $r->successful()) {
            $template->update(['status' => 'failed', 'message' => 'Claude: '.$r->status().' '.mb_substr((string) $r->json('error.message'), 0, 200)]);

            return 0;
        }
        $text = (string) collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode('');
        // The JSON may come with words or a code fence around it: take the outermost {...}.
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        $json = $start !== false && $end > $start ? json_decode(substr($text, $start, $end - $start + 1), true) : null;
        if (! is_array($json['positions'] ?? null)) {
            $why = $r->json('stop_reason') === 'max_tokens' ? 'reply cut off (too long)' : 'not JSON: '.mb_substr(trim(preg_replace('/\s+/', ' ', $text)), 0, 160);
            $template->update(['status' => 'failed', 'message' => 'Claude reply was not usable: '.$why]);

            return 0;
        }

        $num = fn ($v) => is_numeric($v) && $v > 0 ? (int) round((float) $v) : null;
        $positions = collect($json['positions'])->filter(fn ($p) => is_array($p) && filled($p['name'] ?? null))->take(12)->values();
        DB::transaction(function () use ($template, $product, $positions, $front, $back, $num) {
            BrandingPosition::query()->where('branding_template_id', $template->id)->where('source', 'template')->delete();
            foreach ($positions as $i => $p) {
                $view = in_array($p['view'] ?? null, self::VIEWS, true) ? $p['view'] : 'front';
                $pic = ['front' => $front, 'back' => $back][$view] ?? null;
                $box = is_array($p['box'] ?? null) && $pic && is_numeric($p['box']['left'] ?? null) && is_numeric($p['box']['top'] ?? null) && is_numeric($p['box']['width'] ?? null) ? $p['box'] : null;
                $confidence = in_array($p['confidence'] ?? null, ['high', 'low'], true) ? $p['confidence'] : null;
                $quad = $pic ? self::quad($p['quad'] ?? null) : null;
                if (! $box && $quad) {
                    $xs = array_column($quad, 0);
                    $ys = array_column($quad, 1);
                    $box = ['left' => min($xs), 'top' => min($ys), 'width' => max($xs) - min($xs)]; // the corners' bounding box
                }
                if ($confidence === 'low') {
                    $box = null; // not where the print really goes on our picture: a guide card, not a mockup
                    $quad = null;
                }
                $surface = in_array($p['surface'] ?? null, self::SURFACES, true) ? $p['surface'] : null;
                // Methods: names, each with its own size where the template gives one.
                $names = [];
                $sizes = [];
                foreach ((array) ($p['methods'] ?? []) as $m) {
                    $name = mb_substr(trim((string) (is_array($m) ? ($m['name'] ?? '') : $m)), 0, 60);
                    if ($name === '' || in_array($name, $names, true)) {
                        continue;
                    }
                    $names[] = $name;
                    if (is_array($m) && ($w = $num($m['max_width_mm'] ?? null))) {
                        $sizes[$name] = [$w, $num($m['max_height_mm'] ?? null) ?? $w];
                    }
                }
                $mw = $num($p['max_width_mm'] ?? null);
                $mh = $num($p['max_height_mm'] ?? null);
                if (! $mw && $sizes !== []) {
                    $big = collect($sizes)->sortByDesc(fn ($s) => $s[0] * $s[1])->first();
                    [$mw, $mh] = $big;
                }
                BrandingPosition::query()->create([
                    'product_id' => $product->id, 'branding_template_id' => $template->id, 'position' => $i,
                    'name' => mb_substr(trim((string) $p['name']), 0, 60), 'view' => $view, 'image_url' => $box ? $pic : null,
                    'left' => $box ? max(0, min(95, (float) $box['left'])) : null, 'top' => $box ? max(0, min(95, (float) $box['top'])) : null,
                    'width' => $box ? max(3, min(100, (float) $box['width'])) : null,
                    'quad' => $box ? $quad : null, 'surface' => $surface,
                    'max_width_mm' => $mw, 'max_height_mm' => $mh,
                    'methods' => $names, 'method_sizes' => $sizes ?: null, 'confidence' => $confidence,
                ]);
            }
            $template->update(['status' => $positions->isEmpty() ? 'none' : 'read', 'message' => null, 'read_at' => now()]);
        });
        $this->applyToLogoArea($product);
        if ($product->tier === Product::TIER_LIVE) {
            $product->forceFill(['range_changed_at' => now()])->save();
        }

        return $positions->count();
    }

    /** The first front position with a box becomes the logo area, unless staff vetted one. */
    public function applyToLogoArea(Product $product): void
    {
        $front = BrandingPosition::query()->where('product_id', $product->id)->where('view', 'front')->whereNotNull('left')->orderBy('position')->first();
        $hero = $this->areas->heroOf($product);
        if (! $front || ! $hero || $front->image_url !== $hero) {
            return;
        }
        $existing = PrintArea::query()->find(sha1($hero));
        if ($existing?->approved_at) {
            return;
        }
        PrintArea::query()->updateOrCreate(['url_hash' => sha1($hero)], [
            'url' => $hero, 'product_id' => $product->id, 'group_key' => $this->areas->groupKey($product),
            'left' => $front->left, 'top' => $front->top, 'width' => $front->width,
            'pred_left' => $front->left, 'pred_top' => $front->top, 'pred_width' => $front->width, 'source' => 'template',
        ]);
    }

    /** What Shopify gets: custom.brandingpositions (json). @return list<array<string, mixed>> */
    public function forShopify(Product $product): array
    {
        return BrandingPosition::query()->where('product_id', $product->id)->orderBy('position')->get()
            ->map(fn ($p) => array_filter([
                'name' => $p->name, 'view' => $p->view, 'image' => $p->image_url,
                'left' => $p->left, 'top' => $p->top, 'width' => $p->width,
                'quad' => $p->quad ?: null, 'surface' => $p->surface,
                'max_mm' => $p->max_width_mm ? [$p->max_width_mm, $p->max_height_mm] : null,
                'methods' => $p->methods ?: null,
                'method_sizes' => $p->method_sizes ?: null,
            ], fn ($v) => $v !== null))->values()->all();
    }

    /**
     * Not sent to Shopify any more (Scott, 3 Nov 2026): no template links on our product pages, so a bot
     * can't collect them. ProductPayload sends custom.hasbrandingtemplate instead; the template goes out
     * attached to the quote or invoice email (TemplateFiles).
     */
    public function templateUrl(Product $product): ?string
    {
        return BrandingTemplate::query()->where('product_id', $product->id)->where('status', 'read')->latest('read_at')->value('url');
    }
}
