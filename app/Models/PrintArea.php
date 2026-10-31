<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrintArea extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'url_hash';

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['left' => 'float', 'top' => 'float', 'width' => 'float', 'approved_at' => 'datetime'];
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
