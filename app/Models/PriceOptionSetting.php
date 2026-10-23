<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceOptionSetting extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type_code_override' => 'integer',
            'is_default' => 'boolean',
            'is_other' => 'boolean',
            'json_sort' => 'integer',
        ];
    }
}
