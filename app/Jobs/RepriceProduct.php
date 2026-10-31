<?php

namespace App\Jobs;

use App\Catalogue\RangeManager;
use App\Models\Product;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Reprices one live/active product after its supplier data or a decoration type changes. */
class RepriceProduct implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public readonly int $productId) {}

    public function handle(RangeManager $range): void
    {
        $product = Product::query()->find($this->productId);
        if ($product !== null) {
            $range->reprice($product);
        }
    }
}
