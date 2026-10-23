<?php

namespace App\Models;

use App\Pricing\PriceString;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceOption extends Model
{
    public const KIND_UNDECORATED = 'UNDEC';

    public const KIND_DECORATED = 'PDADEC';

    public const KIND_EXTERNAL = 'EXT';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type_code' => 'integer',
            'setup_cost' => 'float',
            'setup_price' => 'float',
            'placeholder' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function costs(): PriceString
    {
        return PriceString::parse($this->cost_string);
    }

    public function prices(): PriceString
    {
        return PriceString::parse($this->price_string);
    }
}
