<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    public $incrementing = false;

    protected $keyType = 'int';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'web_flag' => 'boolean',
            'sample_available' => 'boolean',
            'markup_undecorated' => 'decimal:4',
            'markup_decoration' => 'decimal:4',
            'markup_accessory' => 'decimal:4',
            'markup_setup' => 'decimal:4',
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
