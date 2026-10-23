<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PriceLine extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'setup_cost' => 'decimal:4',
            'undecorated' => 'boolean',
            'indent' => 'boolean',
            'tags' => 'array',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function basePriceLine(): BelongsTo
    {
        return $this->belongsTo(PriceLine::class, 'base_price_line_id');
    }

    public function breaks(): HasMany
    {
        return $this->hasMany(PriceBreak::class)->orderBy('qty');
    }
}
