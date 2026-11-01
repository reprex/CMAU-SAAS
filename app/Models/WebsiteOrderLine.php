<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebsiteOrderLine extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['qty' => 'integer', 'unit_ex_gst' => 'decimal:2', 'total_ex_gst' => 'decimal:2', 'stock_at_order' => 'integer', 'factory' => 'integer', 'supplier_id' => 'integer'];
    }
}
