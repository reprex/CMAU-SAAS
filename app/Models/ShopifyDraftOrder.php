<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopifyDraftOrder extends Model
{
    public $incrementing = false;

    protected $keyType = 'int';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'anonymous' => 'boolean',
            'shopify_created_at' => 'datetime',
            'shopify_updated_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return json_decode($this->payload, true) ?: [];
    }
}
