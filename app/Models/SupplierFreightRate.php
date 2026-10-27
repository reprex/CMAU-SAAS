<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierFreightRate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'freight_cost' => 'decimal:2',
            'freight_sell' => 'decimal:2',
            'free_freight_over' => 'decimal:2',
            'freight_decorator_leg' => 'boolean',
            'effective_from' => 'date',
        ];
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
