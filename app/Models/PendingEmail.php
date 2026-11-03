<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An email the app has prepared: a client invoice, a staff quote or an internal change-request
 * notice. "draft" waits for staff to send it (REPSTACK principle 6); "queued" is
 * sent by the next minute cron when website.orders.auto_send_invoice is on.
 */
class PendingEmail extends Model
{
    public const KIND_INVOICE = 'invoice';

    public const KIND_CHANGE = 'change_request';

    public const KIND_QUOTE = 'quote';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(WebsiteOrder::class, 'website_order_id');
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function change(): BelongsTo
    {
        return $this->belongsTo(WebsiteOrderChange::class, 'website_order_change_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
