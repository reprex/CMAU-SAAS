<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductWebPrice extends Model
{
    protected $primaryKey = 'product_id';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'decoration_available' => 'boolean',
            'zero_price_decoration' => 'boolean',
            'order_value_100' => 'float',
            'order_value_moq' => 'float',
            'order_value_display' => 'float',
            'collection_variant_price' => 'float',
            'warnings' => 'array',
            'computed_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
