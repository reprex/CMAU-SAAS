<?php

namespace App\Console\Commands;

use App\Models\ShopifyDraftOrder;
use App\Quotes\DraftOrderIntake;
use Illuminate\Console\Command;

/**
 * Loads draft orders from a JSON file (an array of Shopify draft orders, in
 * Shopify's own JSON or the DraftOrdersLocal export's PascalCase), e.g. to bring
 * open requests across at cut-over. Customer data: run on the server only.
 */
class QuotesImportDraftsCommand extends Command
{
    protected $signature = 'quotes:import-drafts {file} {--open-only : Skip completed draft orders}';

    protected $description = 'Import Shopify draft orders from a JSON file';

    public function handle(DraftOrderIntake $intake): int
    {
        $rows = json_decode((string) file_get_contents($this->argument('file')), true);
        if (! is_array($rows)) {
            $this->error('The file is not a JSON array of draft orders.');

            return self::FAILURE;
        }

        $bar = $this->output->createProgressBar(count($rows));
        foreach ($rows as $raw) {
            $status = mb_strtolower((string) ($raw['status'] ?? $raw['Status'] ?? ''));
            if (! $this->option('open-only') || $status === 'open') {
                $intake->receive($raw);
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        $this->table(['Draft orders', 'Quote requests', 'Anonymous wish lists', 'Errors'], [[
            ShopifyDraftOrder::count(),
            ShopifyDraftOrder::whereNotNull('quote_id')->count(),
            ShopifyDraftOrder::where('anonymous', true)->whereNull('quote_id')->count(),
            ShopifyDraftOrder::whereNotNull('error')->count(),
        ]]);

        return self::SUCCESS;
    }
}
