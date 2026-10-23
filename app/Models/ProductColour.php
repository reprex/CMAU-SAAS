<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductColour extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'swatch_urls' => 'array',
            'supplier_colours' => 'array',
            'appa_colours' => 'array',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
