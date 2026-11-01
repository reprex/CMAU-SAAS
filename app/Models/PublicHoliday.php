<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A public holiday in one state; business days skip them (docs/website-checkout.md). */
class PublicHoliday extends Model
{
    public const STATES = ['VIC', 'NSW', 'QLD', 'SA', 'WA', 'TAS', 'NT', 'ACT'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }
}
