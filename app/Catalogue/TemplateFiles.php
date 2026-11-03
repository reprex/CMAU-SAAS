<?php

namespace App\Catalogue;

use App\Models\BrandingTemplate;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Branding templates as private files (Scott, 3 Nov 2026; docs/branding-templates.md). They are never
 * linked from the product page, so a bot can't collect them from our site; the page only says one is
 * available. They go out attached to the quote and invoice emails we send, and staff download them
 * from Range › product.
 *
 * A template's file is the PDF staff reviewed and imported (catalogue:import-templates), else a copy
 * of its address (Promodata line art, the supplier's pattern, a URL staff added) fetched the first
 * time an email needs it.
 */
class TemplateFiles
{
    public const DISK = 'templates';

    /**
     * Import a folder of reviewed templates: each file is named "<supplier code> <anything>.pdf"
     * (e.g. "AP001 Jackys Cotton Apron.pdf", "100109_Branding Template_1.pdf", "LL0007 Monza Car Charger.pdf").
     *
     * @param  callable(string): void|null  $say
     * @return array{imported: int, same: int, unmatched: list<string>}
     */
    public function import(string $dir, ?int $supplierId = null, ?callable $say = null): array
    {
        $out = ['imported' => 0, 'same' => 0, 'unmatched' => []];
        $files = collect(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)))
            ->filter(fn (\SplFileInfo $f) => $f->isFile() && preg_match('/\.(pdf|png|jpe?g|ai|eps)$/i', $f->getFilename()))
            ->sortBy(fn (\SplFileInfo $f) => $f->getFilename())->values();
        foreach ($files as $f) {
            $name = $f->getFilename();
            $product = $this->productFor($name, $supplierId);
            if (! $product) {
                $out['unmatched'][] = $name;

                continue;
            }
            $path = $product->supplier_id.'/'.$this->safe($name);
            $bytes = (int) $f->getSize();
            $row = BrandingTemplate::query()->where('product_id', $product->id)->where('filename', $name)->first();
            if ($row && (int) $row->bytes === $bytes && Storage::disk(self::DISK)->exists($path)) {
                $out['same']++;

                continue;
            }
            $stream = fopen($f->getPathname(), 'rb');
            Storage::disk(self::DISK)->writeStream($path, $stream);
            is_resource($stream) && fclose($stream);
            $first = ! $this->has($product);
            BrandingTemplate::query()->updateOrCreate(['product_id' => $product->id, 'filename' => $name], [
                'url' => 'file:'.$path, 'file_path' => $path, 'bytes' => $bytes, 'source' => 'file',
                // Read for positions too (catalogue:read-templates), unless the product's template was already read.
                'status' => BrandingTemplate::query()->where('product_id', $product->id)->where('status', 'read')->exists() ? 'stored' : 'new',
            ]);
            if ($first && $product->tier === Product::TIER_LIVE) {
                $product->forceFill(['range_changed_at' => now()])->save(); // custom.hasbrandingtemplate goes to Shopify
            }
            $out['imported']++;
            $say && $out['imported'] % 100 === 0 && $say("{$out['imported']} imported…");
        }

        return $out;
    }

    /** The product a file belongs to: the code before the first space or underscore. */
    public function productFor(string $filename, ?int $supplierId = null): ?Product
    {
        if (! preg_match('/^([A-Za-z0-9.\-]+?)(?:[ _(]|\.[a-z]{2,4}$)/', $filename, $m)) {
            return null;
        }
        $q = Product::query()->where('code', $m[1])->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId));
        $all = $q->orderByRaw("case when tier = 'live' then 0 else 1 end")->limit(2)->get();

        return $all->count() === 1 || $supplierId ? $all->first() : null; // a code two suppliers share needs --supplier
    }

    /** Does the product have a template we can send (a file, or an address we can fetch)? */
    public function has(Product|int $product): bool
    {
        return BrandingTemplate::query()->where('product_id', is_int($product) ? $product : $product->id)
            ->where(fn ($w) => $w->whereNotNull('file_path')->orWhere('status', 'read'))->exists();
    }

    /**
     * The templates to send for these products: the imported files, else the template that was read.
     *
     * @param  iterable<int>  $productIds
     * @return Collection<int, BrandingTemplate>
     */
    public function forProducts(iterable $productIds): Collection
    {
        $ids = collect($productIds)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }
        $all = BrandingTemplate::query()->whereIn('product_id', $ids)->where(fn ($w) => $w->whereNotNull('file_path')->orWhere('status', 'read'))
            ->orderBy('product_id')->orderByRaw('case when file_path is null then 1 else 0 end')->orderBy('filename')->orderBy('id')->get();

        return $all->groupBy('product_id')->flatMap(fn (Collection $rows) => $rows->whereNotNull('file_path')->isNotEmpty() ? $rows->whereNotNull('file_path') : $rows->take(1))->values();
    }

    /**
     * Email attachments for these products, largest total config('quotes.templates.max_attach_mb').
     *
     * @param  iterable<int>  $productIds
     * @return array{files: list<array{name: string, data: string, mime: string}>, left_out: list<string>}
     */
    public function attachments(iterable $productIds): array
    {
        $max = (float) config('quotes.templates.max_attach_mb', 15) * 1048576;
        $out = ['files' => [], 'left_out' => []];
        $total = 0;
        $products = Product::query()->whereIn('id', collect($productIds)->filter()->unique())->get(['id', 'supplier_id', 'code', 'name'])->keyBy('id');
        foreach ($this->forProducts($productIds) as $t) {
            $p = $products[$t->product_id] ?? null;
            $name = $this->attachName($t, $p);
            $data = $this->bytes($t);
            if ($data === null) {
                continue;
            }
            if ($total + strlen($data) > $max) {
                $out['left_out'][] = $name;

                continue;
            }
            $total += strlen($data);
            $out['files'][] = ['name' => $name, 'data' => $data, 'mime' => $this->mime($name)];
        }

        return $out;
    }

    /** The file's bytes; an address is fetched once and kept as the template's file. */
    public function bytes(BrandingTemplate $t): ?string
    {
        $disk = Storage::disk(self::DISK);
        if ($t->file_path && $disk->exists($t->file_path)) {
            return $disk->get($t->file_path);
        }
        if (! preg_match('#^https?://#', (string) $t->url)) {
            return null;
        }
        try {
            $r = Http::timeout(20)->get($t->url);
        } catch (Throwable) {
            return null;
        }
        if (! $r->successful() || strlen($r->body()) < 200) {
            return null;
        }
        $ext = preg_match('/\.(pdf|png|jpe?g)(\?|$)/i', $t->url, $m) ? strtolower($m[1]) : 'pdf';
        $path = 'fetched/'.$t->product_id.'-'.$t->id.'.'.$ext;
        $disk->put($path, $r->body());
        $t->forceFill(['file_path' => $path, 'bytes' => strlen($r->body()), 'filename' => $t->filename ?: basename((string) parse_url($t->url, PHP_URL_PATH))])->save();

        return $r->body();
    }

    /** "Branding template AP001 Jackys Cotton Apron.pdf": our name for the attachment, no supplier name. */
    public function attachName(BrandingTemplate $t, ?Product $p): string
    {
        $ext = preg_match('/\.([a-z0-9]{2,4})$/i', (string) ($t->filename ?: $t->file_path ?: parse_url((string) $t->url, PHP_URL_PATH)), $m) ? strtolower($m[1]) : 'pdf';
        $label = $p ? trim(OurCodes::code($p).' '.$p->name) : 'product '.$t->product_id;
        $n = preg_match('/\((\d+)\)\.[a-z0-9]+$/i', (string) $t->filename, $mm) ? ' ('.$mm[1].')' : '';

        return $this->safe('Branding template '.$label.$n.'.'.$ext);
    }

    private function mime(string $name): string
    {
        return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'png' => 'image/png', 'jpg', 'jpeg' => 'image/jpeg', 'ai', 'eps' => 'application/postscript', default => 'application/pdf',
        };
    }

    private function safe(string $name): string
    {
        return trim(preg_replace('/[\\\\\/:*?"<>|]+/', '', $name));
    }
}
