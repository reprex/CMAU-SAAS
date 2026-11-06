<?php

namespace App\Console\Commands;

use App\Shopify\StoreMenus;
use Illuminate\Console\Command;

/** Shows, then with --apply writes, the CrowdMerch menus (config/shopify-menus.php; docs/menus.md). */
class ShopifyMenusCommand extends Command
{
    protected $signature = 'shopify:menus {--apply : Create or replace the menus on the store}';

    protected $description = 'Create or replace the CrowdMerch store menus (main, popular, footer)';

    public function handle(StoreMenus $menus): int
    {
        $plan = $menus->plan();
        foreach ($plan as $handle => $menu) {
            $this->line("<info>{$handle}</info> ({$menu['title']}): ".($menu['existing'] ? 'replace' : 'create'));
            foreach ($menu['items'] as $i) {
                $this->line("  {$i['title']} -> {$i['url']}");
                foreach ($i['children'] as $c) {
                    $this->line("      {$c['title']} -> {$c['url']}");
                }
            }
        }
        foreach (array_unique($menus->notes) as $n) {
            $this->warn($n);
        }
        if (! $this->option('apply')) {
            $this->info('Nothing written. Add --apply (st menusgo) to write these menus.');

            return self::SUCCESS;
        }
        try {
            $result = $menus->apply($plan);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $failed = false;
        foreach ($result as $handle => $r) {
            $failed = $failed || str_starts_with($r, 'FAILED');
            $this->line("{$handle}: {$r}");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
