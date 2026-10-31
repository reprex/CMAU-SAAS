<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrandingPosition extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['methods' => 'array', 'left' => 'float', 'top' => 'float', 'width' => 'float'];
    }
}
