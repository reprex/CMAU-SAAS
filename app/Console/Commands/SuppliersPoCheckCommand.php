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
        $rows = Supplier::query()->with('poSupplier')->whereIn('id', $counts->keys())->orderBy('name')->get()
            ->map(function (Supplier $s) use ($counts) {
                $to = $s->poRecipient();

                return [$s->id, $s->name, $counts[$s->id], $to->id === $s->id ? '-' : $to->name, $to->orders_contact ?: '-', $to->orders_email ?: 'MISSING', $to->our_account_number ?: '-', $to->dispatch_postcode ? trim($to->dispatch_suburb.' '.$to->dispatch_postcode) : 'MISSING'];
            });
        $this->table(['ID', 'Supplier', 'Live products', 'PO to', 'Orders contact', 'Orders email', 'Our account', 'Dispatch'], $rows->all());
        $this->line($rows->where(5, 'MISSING')->count().' of '.$rows->count().' need an orders email, '.$rows->where(7, 'MISSING')->count().' a dispatch address (Admin › Suppliers).');

        return self::SUCCESS;
    }
}
