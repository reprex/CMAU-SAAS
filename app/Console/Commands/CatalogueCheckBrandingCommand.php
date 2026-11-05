<?php

namespace App\Console\Commands;

use App\Catalogue\GarmentSizes;
use App\Catalogue\PhotoBrandings;
use App\Catalogue\PrintAreas;
use App\Models\BrandingPosition;
use App\Models\PhotoBranding;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Example branding on mockup photos (docs/branding-templates.md, "Photos with example branding"): Claude looks
 * at each colour photo a mockup could be drawn on, once. Only products that can get a mockup at all: a template
 * read, a staff logo area, or a garment with the supplier's size chart. Every minute from the cron; --all to
 * work through the range in one go.
 */
class CatalogueCheckBrandingCommand extends Command
{
    protected $signature = 'catalogue:check-branding
        {--product= : One product}
        {--all : Every product not checked yet, until done (or --seconds)}
        {--products=12 : Products to look at this run (the cron)}
        {--parallel=5 : Requests to Claude at once}
        {--seconds=45 : Stop after this many seconds (0: no limit)}
        {--supplier= : Only this supplier\'s products}';

    protected $description = 'Check mockup photos for example branding';

    public function handle(PhotoBrandings $branding, PrintAreas $areas): int
    {
        if (! $branding->enabled()) {
            $this->line('Photo branding check is off (PHOTO_BRANDING_CHECK or IMAGE_CHECK, and ANTHROPIC_API_KEY).');

            return self::SUCCESS;
        }
        if ($id = $this->option('product')) {
            $product = Product::query()->with(['images', 'colours', 'override'])->findOrFail((int) $id);
            $n = $branding->checkMany([$product])[$product->id] ?? 0;
            $this->info("Checked {$n} photos.");
            foreach ($branding->photos($product) as $p) {
                $row = PhotoBranding::query()->find(PhotoBranding::hashOf($p['url']));
                $this->line("  {$p['colour']} {$p['view']}: ".($row ? ($row->branding ?? 'could not check').($row->box ? ' at '.implode(',', $row->box) : '').($row->plain === false ? ', textured' : '').($row->note ? " ({$row->note})" : '') : 'not checked'));
            }

            return self::SUCCESS;
        }

        $started = microtime(true);
        $seconds = (int) $this->option('seconds');
        $want = $this->option('all') ? PHP_INT_MAX : (int) $this->option('products');
        $parallel = max(1, (int) $this->option('parallel'));
        $cursorKey = 'photo-branding:cursor'.($this->option('supplier') ? ':'.$this->option('supplier') : '');
        $cursor = (int) Cache::get($cursorKey, 0);
        $looked = $products = $photos = 0;
        $todo = [];
        while ($products < $want) {
            if ($seconds > 0 && microtime(true) - $started > $seconds) {
                break;
            }
            if (! $branding->canCheck()) {
                $this->warn('Stopped: today\'s limit (PHOTO_BRANDING_PER_DAY) is reached.');
                break;
            }
            $batch = Product::query()->with(['images', 'colours', 'override'])->where('tier', Product::TIER_LIVE)->where('discontinued', false)
                ->whereNotNull('hydrated_at')->when($this->option('supplier'), fn ($q, $s) => $q->where('supplier_id', (int) $s))
                ->where('id', '>', $cursor)->orderBy('id')->limit(100)->get();
            if ($batch->isEmpty()) {
                $cursor = 0; // round again from the start next run (new photos, new products)
                break;
            }
            foreach ($batch as $product) {
                $cursor = $product->id;
                $looked++;
                if (! $this->mockable($product, $areas) || $branding->unchecked($product) === []) {
                    continue;
                }
                $todo[] = $product;
                if (count($todo) >= $parallel) {
                    [$p, $n] = $this->check($branding, $todo, $parallel);
                    $products += $p;
                    $photos += $n;
                    $todo = [];
                    if ($products >= $want || ($seconds > 0 && microtime(true) - $started > $seconds)) {
                        break 2;
                    }
                }
            }
            if ($this->option('all') && $looked % 500 < 100) {
                $this->line("  … {$looked} products looked at, {$photos} photos checked");
            }
        }
        if ($todo !== []) {
            [$p, $n] = $this->check($branding, $todo, $parallel);
            $products += $p;
            $photos += $n;
        }
        Cache::forever($cursorKey, $cursor);
        $this->info("Checked {$photos} photos on {$products} products ({$looked} looked at).");

        return self::SUCCESS;
    }

    /** @param list<Product> $todo @return array{0: int, 1: int} */
    private function check(PhotoBrandings $branding, array $todo, int $parallel): array
    {
        try {
            $done = $branding->checkMany($todo, $parallel);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return [0, 0];
        }

        return [count(array_filter($done)), array_sum($done)];
    }

    /** A product that can get a mockup: a template read, a staff logo area, or a garment with a size chart. */
    private function mockable(Product $product, PrintAreas $areas): bool
    {
        if (BrandingPosition::query()->where('product_id', $product->id)->exists()) {
            return true;
        }
        if (($a = $areas->find($product)) && $a->approved_at) {
            return true;
        }

        return (bool) rescue(fn () => GarmentSizes::metafield($product), null, false);
    }
}
