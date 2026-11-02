<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A staff pass for the website's staff tools (docs/website-staff.md). Only the hash is kept. */
class WebsiteStaffPass extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['scopes' => 'array', 'expires_at' => 'datetime', 'last_used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
