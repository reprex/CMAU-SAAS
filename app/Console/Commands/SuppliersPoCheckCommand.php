<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Console\Command;

/** Which suppliers on the web range still need purchase-order details (docs/purchase-orders.md). */
class SuppliersPoCheckCommand extends Command
{
    protected $signature = 'suppliers:po-check';

    protected $description = 'List suppliers with live web products and whether their orders contact and email are filled in';

    public function handle(): int
    {
        $counts = Product::query()->where('tier', Product::TIER_LIVE)->where('discontinued', false)
            ->selectRaw('supplier_id, count(*) n')->groupBy('supplier_id')->pluck('n', 'supplier_id');
        $rows = Supplier::query()->whereIn('id', $counts->keys())->orderBy('name')->get()
            ->map(fn (Supplier $s) => [$s->id, $s->name, $counts[$s->id], $s->orders_contact ?: '-', $s->orders_email ?: 'MISSING', $s->our_account_number ?: '-']);
        $this->table(['ID', 'Supplier', 'Live products', 'Orders contact', 'Orders email', 'Our account'], $rows->all());
        $this->line($rows->where(4, 'MISSING')->count().' of '.$rows->count().' need an orders email (Admin › Suppliers).');

        return self::SUCCESS;
    }
}
