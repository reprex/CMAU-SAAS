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

    /** The picture that shows a view: hero for the front; for the back an image Claude or the file name marked as a back view. */
    public function pictureFor(Product $product, string $view): ?string
    {
        $tags = $this->images->tags($product->loadMissing(['images', 'colours', 'override']));
        if ($view === 'front') {
            return $tags->firstWhere('role', ImageTagger::HERO_STYLE)['url'] ?? null;
        }
        $style = $tags->where('role', ImageTagger::OTHER_STYLE);

        return $style->first(fn ($t) => preg_match('/\b'.$view.'\b/i', str_replace(['_', '-'], ' ', basename((string) parse_url($t['url'], PHP_URL_PATH))))
            || (($t['seen']['note'] ?? '') && stripos((string) $t['seen']['note'], $view) !== false))['url'] ?? null;
    }

    /** Read one template with Claude. Returns how many positions were found. */
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
        $jobs = [];
        foreach ($templates as $t) {
            $job = $this->prepare($t);
            if ($job === null) {
                $out[$t->id] = 0;
            } else {
                $jobs[] = $job;
            }
        }
        foreach (array_chunk($jobs, max(1, $parallel)) as $chunk) {
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
        }

        return $out;
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
        .'confidence is "high" only when the picture shows that side of the product the same way as the template, so the box is where the print really goes; otherwise "low". '
        .'Reply with JSON only: {"positions":[{"name":string,"view":"front"|"back"|"side"|"top","max_width_mm":number|null,"max_height_mm":number|null,'
        .'"methods":[{"name":string,"max_width_mm":number|null,"max_height_mm":number|null}],"box":{"left":n,"top":n,"width":n}|null,"confidence":"high"|"low"}]}. '
        .'If it is not a branding template, reply {"positions":[]}.';

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
        $json = json_decode(trim(preg_replace('/^```(?:json)?|```$/m', '', (string) collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode(''))), true);
        if (! is_array($json['positions'] ?? null)) {
            $template->update(['status' => 'failed', 'message' => 'Claude reply was not usable']);

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
                if ($confidence === 'low') {
                    $box = null; // not where the print really goes on our picture: a guide card, not a mockup
                }
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
