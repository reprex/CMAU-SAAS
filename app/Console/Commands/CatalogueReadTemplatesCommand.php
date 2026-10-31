<?php

namespace App\Console\Commands;

use App\Catalogue\BrandingTemplates;
use App\Models\BrandingTemplate;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Branding templates (docs/branding-templates.md): each run looks for supplier-
 * pattern templates on a few web-range products and reads up to --limit new
 * templates with Claude. Scheduled every minute.
 */
class CatalogueReadTemplatesCommand extends Command
{
    protected $signature = 'catalogue:read-templates {--product= : One product} {--limit=2}';

    protected $description = 'Find and read supplier branding templates';

    public function handle(BrandingTemplates $templates): int
    {
        if (! config('images.check.enabled') && ! $this->option('product')) {
            return self::SUCCESS;
        }
        if ($id = $this->option('product')) {
            $product = Product::query()->with('supplier')->findOrFail((int) $id);
            $templates->discover($product);
            foreach (BrandingTemplate::query()->where('product_id', $product->id)->get() as $t) {
                $this->info($t->url.': '.$templates->read($t).' positions');
            }

            return self::SUCCESS;
        }

        // Look for supplier-pattern templates, a few products a run.
        $cursor = (int) Cache::get('templates:cursor', 0);
        $batch = Product::query()->with('supplier')->where('tier', Product::TIER_LIVE)->where('id', '>', $cursor)
            ->whereHas('supplier', fn ($q) => $q->whereNotNull('template_url_pattern'))->orderBy('id')->limit(5)->get();
        foreach ($batch as $p) {
            $templates->discover($p);
        }
        Cache::forever('templates:cursor', $batch->isEmpty() ? 0 : $batch->last()->id);

        $read = 0;
        $todo = BrandingTemplate::query()->where('status', 'new')
            ->whereIn('product_id', Product::query()->where('tier', Product::TIER_LIVE)->select('id'))
            ->orderBy('id')->limit((int) $this->option('limit'))->get();
        foreach ($todo as $t) {
            try {
                $templates->read($t);
                $read++;
            } catch (Throwable $e) {
                $t->update(['status' => 'failed', 'message' => mb_substr($e->getMessage(), 0, 500)]);
            }
        }
        $this->info("Read {$read} templates.");

        return self::SUCCESS;
    }
}
