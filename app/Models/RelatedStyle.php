<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A staff-vetted related style of a product (e.g. the womens or kids version),
 * from the same supplier. Website orders may mix a product with its related
 * styles in one print run (docs/website-checkout.md).
 */
class RelatedStyle extends Model
{
    protected $guarded = [];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function related(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'related_product_id');
    }
}
