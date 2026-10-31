<?php

namespace App\Catalogue;

use App\Models\BrandingPosition;
use App\Models\BrandingTemplate;
use App\Models\PrintArea;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
        $product = Product::query()->with(['supplier', 'images', 'colours', 'override'])->findOrFail($template->product_id);
        $dayKey = 'image-check:'.now()->format('Ymd'); // shares the image check's daily cap
        if ((int) Cache::get($dayKey, 0) >= (int) config('images.check.images_per_day') || blank(config('search.anthropic.key'))) {
            return 0;
        }
        $front = $this->pictureFor($product, 'front');
        $back = $this->pictureFor($product, 'back');
        $isPdf = (bool) preg_match('/\.pdf(\?|$)/i', $template->url);

        $content = [
            ['type' => 'text', 'text' => "Supplier branding template for {$product->name} ({$product->supplier?->name} {$product->code}):"],
            $isPdf ? ['type' => 'document', 'source' => ['type' => 'url', 'url' => $template->url]] : ['type' => 'image', 'source' => ['type' => 'url', 'url' => $template->url]],
        ];
        if ($front) {
            $content[] = ['type' => 'text', 'text' => 'FRONT picture of our product:'];
            $content[] = ['type' => 'image', 'source' => ['type' => 'url', 'url' => $front]];
        }
        if ($back) {
            $content[] = ['type' => 'text', 'text' => 'BACK picture of our product:'];
            $content[] = ['type' => 'image', 'source' => ['type' => 'url', 'url' => $back]];
        }
        $system = 'You read supplier branding templates for a branded merchandise store. List every branding position the template shows '
            .'(e.g. Front centre, Left chest, Back, Right sleeve, Lid, Wrap). For each give: name; view (front, back, side or top); the maximum print size in millimetres if stated; '
            .'the decoration methods named for it; and, if a picture of that view is provided, the position as a box on that picture in percent (left, top, width of the picture). '
            .'Reply with JSON only: {"positions":[{"name":string,"view":"front"|"back"|"side"|"top","max_width_mm":number|null,"max_height_mm":number|null,"methods":[string],"box":{"left":n,"top":n,"width":n}|null}]}. '
            .'If it is not a branding template, reply {"positions":[]}.';

        Cache::add($dayKey, 0, now()->endOfDay());
        Cache::increment($dayKey, 1 + ($front ? 1 : 0) + ($back ? 1 : 0));
        $r = Http::withHeaders(['x-api-key' => config('search.anthropic.key'), 'anthropic-version' => '2023-06-01'])
            ->timeout((int) config('images.check.timeout'))
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => config('images.check.model'), 'max_tokens' => 900, 'system' => $system,
                'messages' => [['role' => 'user', 'content' => $content]],
            ]);
        if (! $r->successful()) {
            $template->update(['status' => 'failed', 'message' => 'Claude: '.$r->status().' '.mb_substr((string) $r->json('error.message'), 0, 200)]);

            return 0;
        }
        $json = json_decode(trim(preg_replace('/^```(?:json)?|```$/m', '', (string) collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode(''))), true);
        if (! is_array($json['positions'] ?? null)) {
            $template->update(['status' => 'failed', 'message' => 'Claude reply was not usable']);

            return 0;
        }

        $positions = collect($json['positions'])->filter(fn ($p) => is_array($p) && filled($p['name'] ?? null))->take(12)->values();
        DB::transaction(function () use ($template, $product, $positions, $front, $back) {
            BrandingPosition::query()->where('branding_template_id', $template->id)->where('source', 'template')->delete();
            foreach ($positions as $i => $p) {
                $view = in_array($p['view'] ?? null, self::VIEWS, true) ? $p['view'] : 'front';
                $pic = ['front' => $front, 'back' => $back][$view] ?? null;
                $box = is_array($p['box'] ?? null) && $pic && is_numeric($p['box']['left'] ?? null) && is_numeric($p['box']['top'] ?? null) && is_numeric($p['box']['width'] ?? null) ? $p['box'] : null;
                BrandingPosition::query()->create([
                    'product_id' => $product->id, 'branding_template_id' => $template->id, 'position' => $i,
                    'name' => mb_substr(trim((string) $p['name']), 0, 60), 'view' => $view, 'image_url' => $box ? $pic : null,
                    'left' => $box ? max(0, min(95, (float) $box['left'])) : null, 'top' => $box ? max(0, min(95, (float) $box['top'])) : null,
                    'width' => $box ? max(3, min(100, (float) $box['width'])) : null,
                    'max_width_mm' => is_numeric($p['max_width_mm'] ?? null) ? (int) $p['max_width_mm'] : null,
                    'max_height_mm' => is_numeric($p['max_height_mm'] ?? null) ? (int) $p['max_height_mm'] : null,
                    'methods' => array_values(array_filter(array_map(fn ($m) => mb_substr((string) $m, 0, 60), (array) ($p['methods'] ?? [])))),
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
            ], fn ($v) => $v !== null))->values()->all();
    }

    public function templateUrl(Product $product): ?string
    {
        return BrandingTemplate::query()->where('product_id', $product->id)->where('status', 'read')->latest('read_at')->value('url');
    }
}
