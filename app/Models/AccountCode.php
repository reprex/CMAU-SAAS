<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A My Orders one-time code (hashed). */
class AccountCode extends Model
{
    protected $guarded = [];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime', 'attempts' => 'integer'];
    }
}
