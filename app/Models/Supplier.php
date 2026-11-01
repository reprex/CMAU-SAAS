<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    public $incrementing = false;

    protected $keyType = 'int';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'web_flag' => 'boolean',
            'sample_available' => 'boolean',
            'markup_undecorated' => 'decimal:4',
            'markup_decoration' => 'decimal:4',
            'markup_accessory' => 'decimal:4',
            'markup_setup' => 'decimal:4',
            'freight_cost' => 'decimal:2',
            'freight_sell' => 'decimal:2',
            'free_freight_over' => 'decimal:2',
            'freight_decorator_leg' => 'boolean',
            'freight_effective_from' => 'date',
            'is_decorator' => 'boolean',
            'stock_lead_days' => 'integer',
            'offers_samples' => 'boolean',
            'sample_freight' => 'decimal:2',
            'sample_lead_days' => 'integer',
        ];
    }

    public const FREIGHT_BASES = [
        'per_delivery' => 'Once per delivery address',
        'per_line' => 'Once per product line',
    ];

    /** Business days to get stock from this supplier (website order delivery estimate). */
    public function stockLeadDays(): int
    {
        return $this->stock_lead_days ?? (int) config('website.lead_times.stock_default');
    }

    /** Business days for this supplier to send website samples: its sample lead time, else its stock lead time. */
    public function sampleLeadDays(): int
    {
        return $this->sample_lead_days ?? $this->stockLeadDays();
    }

    public function hasStandardFreight(): bool
    {
        return $this->freight_sell !== null;
    }

    public function freightRates(): HasMany
    {
        return $this->hasMany(SupplierFreightRate::class)->latest('id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
