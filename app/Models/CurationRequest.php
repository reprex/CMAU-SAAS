<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** A range change asked for from the website's product page (docs/website-staff.md). */
class CurationRequest extends Model
{
    public const STATUSES = ['pending' => 'Waiting for approval', 'approved' => 'Applying', 'applied' => 'Applied', 'rejected' => 'Rejected', 'failed' => 'Failed'];

    public const LABELS = [
        'title' => 'Title', 'description' => 'Description', 'hero_image_url' => 'Hero picture', 'hidden_colours' => 'Hidden colours',
        'collection_moq' => 'Display minimum', 'actual_moq' => 'Production minimum', 'positions' => 'Branding positions', 'on_website' => 'On the website',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['edits' => 'array', 'before' => 'array', 'decided_at' => 'datetime', 'applied_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** One readable line per change, for screens and lists. */
    public function summary(): array
    {
        $out = [];
        foreach ((array) $this->edits as $k => $v) {
            $label = self::LABELS[$k] ?? $k;
            $out[] = $label.': '.match (true) {
                $v === null => 'back to automatic',
                $k === 'on_website' => $v ? 'yes' : 'take it off',
                $k === 'hidden_colours' => $v ? implode(', ', $v) : 'none',
                $k === 'positions' => implode('; ', array_map(fn ($p) => $p['name'].($p['width_mm'] ? " {$p['width_mm']}×{$p['height_mm']} mm" : '').($p['view'] !== 'front' ? " ({$p['view']})" : ''), $v)),
                $k === 'hero_image_url' => basename(parse_url($v, PHP_URL_PATH) ?: $v),
                $k === 'description' => Str::limit(strip_tags($v), 80),
                default => (string) $v,
            };
        }

        return $out;
    }
}
