<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DecTypeCode extends Model
{
    public const DECORATION = 10;

    public const ACCESSORY = 11;

    public const UNDECORATED = 12;

    public const HIDDEN = 23;

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type_code' => 'integer',
            'spot_colour' => 'boolean',
            'full_colour' => 'boolean',
            'embroider' => 'boolean',
            'engrave' => 'boolean',
            'emboss_deboss' => 'boolean',
            'foil_stamp' => 'boolean',
            'sublimation' => 'boolean',
        ];
    }

    /** Decoration descriptions waiting for staff to classify. */
    public function scopeUnclassified(Builder $query): Builder
    {
        return $query->whereNull('type_code');
    }
}
