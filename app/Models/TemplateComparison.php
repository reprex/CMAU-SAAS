<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TemplateComparison extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['positions' => 'array'];
    }
}
