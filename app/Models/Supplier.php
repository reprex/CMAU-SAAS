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
        ];
    }

    public const FREIGHT_BASES = [
        'per_delivery' => 'Once per delivery address',
        'per_line' => 'Once per product line',
    ];

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
