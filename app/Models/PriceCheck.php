<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceCheck extends Model
{
    /** What the supplier's own distributor prices showed. */
    public const VERDICTS = [
        'base_branded' => 'Base price includes branding',
        'base_unbranded' => 'Base price is unbranded (branding on top)',
        'differs' => "Promodata doesn't match the supplier",
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['prices_changed_at' => 'datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }
}
