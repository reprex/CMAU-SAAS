<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A range change to vet (docs/range.md): a supplier's new product to approve, or a discontinued one to follow up. */
class RangeSuggestion extends Model
{
    public const NEW = 'new';

    public const DISCONTINUED = 'discontinued';

    public const PREPARING = 'preparing';

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const DECLINED = 'declined';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['ready' => 'boolean', 'checks' => 'array', 'checked_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
