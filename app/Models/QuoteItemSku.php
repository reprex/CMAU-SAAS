<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuoteItemSku extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'stock_on_hand' => 'integer'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(QuoteItem::class, 'quote_item_id');
    }

    public function isShort(): bool
    {
        return $this->stock_on_hand !== null && $this->quantity > $this->stock_on_hand;
    }
}
