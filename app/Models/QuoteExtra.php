<?php

namespace App\Models;

use App\Pricing\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuoteExtra extends Model
{
    public const KINDS = [
        'custom' => 'Custom product',
        'service' => 'Service',
        'freight' => 'Freight',
        'info' => 'Information line',
    ];

    protected $guarded = [];

    protected $touches = ['quote'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'auto' => 'boolean',
            'overridden' => 'boolean',
            'tbc' => 'boolean',
        ];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function total(): float
    {
        return $this->kind === 'info' ? 0.0 : Money::roundToCent((float) $this->quantity * (float) $this->unit_price);
    }

    public function costTotal(): ?float
    {
        if ($this->kind === 'info') {
            return 0.0;
        }

        return $this->unit_cost === null ? null : Money::roundToCent((float) $this->quantity * (float) $this->unit_cost);
    }
}
