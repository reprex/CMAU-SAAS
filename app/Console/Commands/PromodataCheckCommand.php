<?php

namespace App\Console\Commands;

use App\Sources\Promodata\PromodataClient;
use App\Sources\Promodata\PromodataNormaliser;
use Illuminate\Console\Command;
use Throwable;

/** Checks the Promodata token and the response shape without storing anything. */
class PromodataCheckCommand extends Command
{
    protected $signature = 'promodata:check {--product= : Also fetch one product id}';

    protected $description = 'Test the Promodata connection (read-only)';

    public function handle(PromodataClient $client, PromodataNormaliser $normaliser): int
    {
        try {
            $json = $client->listProducts(1);
        } catch (Throwable $e) {
            $this->error('Promodata call failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $items = array_values((array) ($json['data'] ?? []));
        $ok = 0;
        foreach ($items as $item) {
            try {
                $normaliser->normalise((array) $item);
                $ok++;
            } catch (Throwable) {
            }
        }
        $this->info(sprintf('Connected. %s pages, %d products on page 1, %d read correctly.', $json['total_pages'] ?? '?', count($items), $ok));

        if ($id = $this->option('product')) {
            $p = $client->getProduct((int) $id);
            if ($p === null) {
                $this->warn("Product {$id} not found.");
            } else {
                $n = $normaliser->normalise((array) $p['data']);
                $this->info("Product {$n->id}: {$n->name} ({$n->code}), supplier {$n->supplierName}, ".count($n->colours).' colours, '.count($n->priceGroups).' price groups.');
            }
        }

        return self::SUCCESS;
    }
}
