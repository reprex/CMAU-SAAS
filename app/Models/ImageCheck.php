<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImageCheck extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'url_hash';

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['confidence' => 'float', 'checked_at' => 'datetime'];
    }

    public static function hashOf(string $url): string
    {
        return sha1($url);
    }
}
