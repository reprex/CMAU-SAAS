<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One branding position of a website order; its logo is on the private artwork disk. */
class WebsiteOrderPosition extends Model
{
    protected $guarded = [];

    protected $hidden = ['logo_path'];

    protected function casts(): array
    {
        return ['colours' => 'integer', 'width_mm' => 'integer', 'unit_ex_gst' => 'decimal:2', 'setup_ex_gst' => 'decimal:2'];
    }

    public function methodName(): string
    {
        return (string) (config('website.methods.'.$this->method.'.name') ?? $this->method);
    }
}
