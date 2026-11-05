<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Example branding Claude saw on a colour photo (App\Catalogue\PhotoBrandings). */
class PhotoBranding extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'url_hash';

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['box' => 'array', 'plain' => 'boolean', 'checked_at' => 'datetime'];
    }

    public static function hashOf(string $url): string
    {
        return sha1($url);
    }
}
