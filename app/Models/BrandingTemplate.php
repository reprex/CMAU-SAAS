<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BrandingTemplate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(BrandingPosition::class);
    }
}
