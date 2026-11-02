<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Staff-curated branding positions for a product type or one product (docs/logo-preview.md). */
class PositionRule extends Model
{
    public const VIEWS = ['front', 'back', 'side', 'top'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['positions' => 'array', 'product_id' => 'integer'];
    }

    /** The product's own rule, else its type's, else null. */
    public static function for(Product $product): ?self
    {
        return self::query()->where('product_id', $product->id)->first()
            ?? ($product->promodata_type_name ? self::query()->where('product_type', $product->promodata_type_name)->first() : null);
    }
}
