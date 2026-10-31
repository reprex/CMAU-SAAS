<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    public const TIER_SUMMARY = 'summary';

    public const TIER_ACTIVE = 'active';

    public const TIER_LIVE = 'live';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'discontinued' => 'boolean',
            'can_check_stock' => 'boolean',
            'min_unit_cost' => 'decimal:4',
            'undecorated_cost' => 'decimal:4',
            'dim_1' => 'float',
            'dim_2' => 'float',
            'dim_3' => 'float',
            'lead_time_days' => 'integer',
            'undecorated_moq' => 'integer',
            'summary_version' => 'integer',
            'first_listed_at' => 'datetime',
            'last_changed_at' => 'datetime',
            'prices_changed_at' => 'datetime',
            'discontinued_at' => 'datetime',
            'hydrated_at' => 'datetime',
            'last_used_at' => 'datetime',
            'range_changed_at' => 'datetime',
            'published_at' => 'datetime',
            'price_flags' => 'array',
        ];
    }

    /** Products whose full detail is kept in the database. */
    public function keepsFullDetail(): bool
    {
        return in_array($this->tier, [self::TIER_ACTIVE, self::TIER_LIVE], true);
    }

    public function scopeFullDetail(Builder $query): Builder
    {
        return $query->whereIn('tier', [self::TIER_ACTIVE, self::TIER_LIVE]);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function detail(): HasOne
    {
        return $this->hasOne(ProductDetail::class);
    }

    public function colours(): HasMany
    {
        return $this->hasMany(ProductColour::class)->orderBy('position');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position');
    }

    public function priceLines(): HasMany
    {
        return $this->hasMany(PriceLine::class)->orderBy('position');
    }

    public function override(): HasOne
    {
        return $this->hasOne(ProductOverride::class);
    }

    public function priceOptions(): HasMany
    {
        return $this->hasMany(PriceOption::class)->orderBy('position');
    }

    public function webPrice(): HasOne
    {
        return $this->hasOne(ProductWebPrice::class);
    }

    /** A staff override value (stored in product_overrides.data), or $default. */
    public function overrideValue(string $key, mixed $default = null): mixed
    {
        return data_get($this->override?->data, $key, $default);
    }
}
