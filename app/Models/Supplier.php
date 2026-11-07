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
            'suggest_new' => 'boolean',
            'suggest_types' => 'array',
            'suggest_since' => 'datetime',
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

    /**
     * Where this supplier dispatches from, for delivery prices (docs/freight.md), or null to use
     * config freight.from. Australian suppliers only: NZ (Trends) goes direct and is priced as such.
     *
     * @return array{suburb: string, postcode: string, state: ?string}|null
     */
    public function dispatchOrigin(): ?array
    {
        if (! preg_match('/^\d{4}$/', (string) $this->dispatch_postcode) || ! filled($this->dispatch_suburb) || $this->ships_from === 'NZ') {
            return null;
        }

        return ['suburb' => mb_strtoupper(trim((string) $this->dispatch_suburb)), 'postcode' => (string) $this->dispatch_postcode, 'state' => $this->state];
    }

    /**
     * The supplier's flat delivery for this country (docs/freight.md), or null to use the carriers.
     *
     * @return array{price: float, cost: float, days: int}|null
     */
    public function flatFreight(string $country): ?array
    {
        $countries = array_filter(array_map('trim', explode(',', mb_strtoupper((string) $this->flat_freight_countries))));
        if ($this->flat_freight_price === null || ! in_array(mb_strtoupper($country), $countries, true)) {
            return null;
        }

        return ['price' => (float) $this->flat_freight_price, 'cost' => (float) ($this->flat_freight_cost ?? 0), 'days' => (int) ($this->flat_freight_days ?? 0)];
    }
}
