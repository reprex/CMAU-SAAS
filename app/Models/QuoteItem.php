<?php

namespace App\Models;

use App\Pricing\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One quoted product and its decoration. Prices are sell prices ex GST;
 * costs are optional (known for range products, entered for custom ones).
 */
class QuoteItem extends Model
{
    public const STAGES = ['combined', 'separated', 'sku'];

    protected $guarded = [];

    protected $touches = ['quote'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'received_unit_price' => 'decimal:4',
            'product_unit_price' => 'decimal:4',
            'product_unit_cost' => 'decimal:4',
            'product_setup_price' => 'decimal:4',
            'product_setup_cost' => 'decimal:4',
            'decoration_unit_price' => 'decimal:4',
            'decoration_unit_cost' => 'decimal:4',
            'decoration_setup_price' => 'decimal:4',
            'decoration_setup_cost' => 'decimal:4',
            'stock_columns' => 'array',
            'stock_checked_at' => 'datetime',
        ];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function skus(): HasMany
    {
        return $this->hasMany(QuoteItemSku::class)->orderBy('position')->orderBy('id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function hasDecoration(): bool
    {
        return $this->decoration_name !== null && $this->decoration_name !== '';
    }

    /** Branded price each, setups excluded. */
    public function unitPrice(): float
    {
        return Money::roundToCent((float) $this->product_unit_price + (float) $this->decoration_unit_price);
    }

    public function setupPrice(): float
    {
        return Money::roundToCent((float) $this->product_setup_price + (float) $this->decoration_setup_price);
    }

    public function goodsTotal(): float
    {
        return Money::roundToCent($this->quantity * $this->unitPrice());
    }

    public function productGoodsTotal(): float
    {
        return Money::roundToCent($this->quantity * (float) $this->product_unit_price);
    }

    public function decorationGoodsTotal(): float
    {
        return Money::roundToCent($this->quantity * (float) $this->decoration_unit_price);
    }

    public function total(): float
    {
        return Money::roundToCent($this->goodsTotal() + $this->setupPrice());
    }

    /** Cost to us, or null when any part is unknown. */
    public function costTotal(): ?float
    {
        $parts = [$this->product_unit_cost, $this->product_setup_cost];
        if ($this->hasDecoration()) {
            $parts[] = $this->decoration_unit_cost;
            $parts[] = $this->decoration_setup_cost;
        }
        foreach ($parts as $p) {
            if ($p === null) {
                return null;
            }
        }

        return Money::roundToCent(
            $this->quantity * ((float) $this->product_unit_cost + (float) $this->decoration_unit_cost)
            + (float) $this->product_setup_cost + (float) $this->decoration_setup_cost
        );
    }

    public function skuQuantity(): int
    {
        return (int) $this->skus->sum('quantity');
    }

    /** "XS 5 · S 6 …" grouped by colour when there is more than one. */
    public function sizeBreakdown(): array
    {
        $groups = [];
        foreach ($this->skus as $sku) {
            if ($sku->quantity <= 0) {
                continue;
            }
            $groups[$sku->colour ?? ''][] = ['size' => $sku->size ?: 'One size', 'qty' => $sku->quantity];
        }

        return $groups;
    }
}
