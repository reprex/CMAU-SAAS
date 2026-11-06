<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quote extends Model
{
    use SoftDeletes;

    /** Workflow statuses (TOD's plus the new intake ones). */
    public const STATUSES = [
        'NEW' => 'New request',
        'SAVELIST' => 'Wish list',
        'PHONE' => 'Phone',
        'QUOTE' => 'Quote sent',
        'ORDER' => 'Won',
        'PROOF SENT' => 'Proof sent',
        'PROOF OK' => 'Proof OK',
        'SHIPPED' => 'Shipped',
        'INVOICED' => 'Invoiced',
        'PAID' => 'Paid',
        'LOST' => 'Lost',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'deadline' => 'date',
            'artwork' => 'array',
            'mockups' => 'array',
            'similar_products' => 'array',
            'saved_products' => 'array',
            'cart_state' => 'array',
            'valid_until' => 'date',
            'sent_at' => 'datetime',
            'won_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'gst' => 'decimal:2',
            'total' => 'decimal:2',
            'cost_total' => 'decimal:2',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class)->orderBy('position')->orderBy('id');
    }

    public function extras(): HasMany
    {
        return $this->hasMany(QuoteExtra::class)->orderBy('position')->orderBy('id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function draftOrder(): BelongsTo
    {
        return $this->belongsTo(ShopifyDraftOrder::class, 'shopify_draft_order_id');
    }

    public function isWishlist(): bool
    {
        return $this->kind === 'wishlist';
    }

    public function displayName(): string
    {
        return $this->client_company ?: ($this->client_name ?: ($this->client_email ?: 'No contact details'));
    }

    public function deliverySuburb(): ?string
    {
        $parts = array_filter([$this->delivery_city, $this->delivery_state]);

        return $parts ? implode(' ', $parts) : null;
    }

    public function nextPosition(): int
    {
        return (int) max($this->items()->max('position'), $this->extras()->max('position')) + 1;
    }

    /**
     * The website quote's "complete the online checkout" link: the storefront's cart page rebuilds the
     * shopper's cart from this quote (Scott, 7 Nov 2026). Null when the quote has no website cart.
     */
    public function checkoutUrl(): ?string
    {
        if (empty($this->cart_state) || ! $this->public_token) {
            return null;
        }

        return rtrim((string) config('website.storefront_url'), '/').'/'.ltrim((string) config('website.cart_path'), '/').'?quote='.$this->public_token;
    }
}
