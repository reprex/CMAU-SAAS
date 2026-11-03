<?php

namespace App\Console\Commands;

use App\Catalogue\GarmentSizes;
use App\Models\Product;
use Illuminate\Console\Command;

/**
 * Read-only report: which garments on the range have a size chart chest width for the website mockup,
 * and examples of size text that couldn't be read (so the reader can learn new layouts).
 */
class CatalogueGarmentSizesCommand extends Command
{
    protected $signature = 'catalogue:garment-sizes {--product= : One product id, with the text it read} {--examples=12 : Unread examples to show}';

    protected $description = 'Report the size chart chest widths found for garment mockups (read-only)';

    private const GARMENT = '/shirt|tee|polo|hood|jumper|jacket|singlet|vest|fleece|sweat|uniform|jersey|apparel|workwear/i';

    public function handle(): int
    {
        if ($id = $this->option('product')) {
            $p = Product::with('detail')->findOrFail((int) $id);
            foreach ((array) ($p->detail?->details ?? []) as $x) {
                $this->line(($x['name'] ?? '').': '.($x['detail'] ?? ''));
            }
            $this->info('Mockup size: '.(GarmentSizes::metafield($p) ?? 'none found (the website uses a typical width)'));

            return self::SUCCESS;
        }

        $found = $missing = 0;
        $examples = [];
        Product::query()->where('tier', Product::TIER_LIVE)->where('discontinued', false)->with('detail')
            ->where(fn ($w) => $w->where('promodata_type_name', 'like', '%Shirt%')->orWhere('promodata_type_name', 'like', '%Jumper%')->orWhere('promodata_type_name', 'like', '%Jacket%')
                ->orWhere('promodata_type_name', 'like', '%Uniform%')->orWhere('promodata_type_name', 'like', '%Workwear%')->orWhere('promodata_type_name', 'like', '%Apparel%')->orWhere('promodata_type_name', 'like', '%Clothing%'))
            ->chunkById(200, function ($products) use (&$found, &$missing, &$examples) {
                foreach ($products as $p) {
                    if (! preg_match(self::GARMENT, $p->promodata_type_name.' '.$p->name)) {
                        continue;
                    }
                    if (GarmentSizes::metafield($p)) {
                        $found++;

                        continue;
                    }
                    $missing++;
                    if (count($examples) < (int) $this->option('examples')) {
                        foreach ((array) ($p->detail?->details ?? []) as $x) {
                            if (preg_match('/chest|measure|size\s*(guide|chart|spec)|width|bust/i', ($x['name'] ?? '').' '.($x['detail'] ?? ''))) {
                                $examples[] = [$p->id, mb_strimwidth(($x['name'] ?? '').': '.($x['detail'] ?? ''), 0, 140, '…')];
                                break;
                            }
                        }
                    }
                }
            });

        $this->info("Garments with a size chart chest width: {$found}. Without one (typical width used): {$missing}.");
        if ($examples) {
            $this->line('Size text that could not be read (send these to Claude to teach the reader):');
            $this->table(['Product', 'Text'], $examples);
        }

        return self::SUCCESS;
    }
}
