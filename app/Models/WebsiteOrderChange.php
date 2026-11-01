<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A client's change request against a website order (My Orders). */
class WebsiteOrderChange extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['handled_at' => 'datetime', 'stage_at_request' => 'integer'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(WebsiteOrder::class, 'website_order_id');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}
