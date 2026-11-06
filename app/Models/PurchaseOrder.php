<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A supplier purchase order (docs/purchase-orders.md). Amounts are supplier cost, ex GST. */
class PurchaseOrder extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'deliver_to' => 'array', 'lines' => 'array', 'branding' => 'array', 'extras' => 'array',
            'need_by' => 'date', 'despatch_by' => 'date', 'sent_at' => 'datetime', 'costs_complete' => 'boolean',
            'goods_cost' => 'decimal:2', 'branding_cost' => 'decimal:2', 'setup_cost' => 'decimal:2', 'freight_cost' => 'decimal:2', 'total_cost' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(WebsiteOrder::class, 'website_order_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function emails(): HasMany
    {
        return $this->hasMany(PendingEmail::class);
    }

    /** The supplier's link to the client artwork (no login; the token is the key). */
    public function artworkUrl(): string
    {
        return route('po-artwork.index', $this->artwork_token);
    }

    public function recalculate(): void
    {
        $this->total_cost = round((float) $this->goods_cost + (float) $this->branding_cost + (float) $this->setup_cost
            + (float) collect($this->extras ?? [])->sum(fn ($x) => (float) ($x['total'] ?? 0)) + (float) ($this->freight_cost ?? 0), 2);
    }
}
