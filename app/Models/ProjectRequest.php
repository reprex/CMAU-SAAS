<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A website project request (PR-xxxxx): docs/website-project-requests.md. */
class ProjectRequest extends Model
{
    protected $guarded = [];

    public const STATUSES = ['new' => 'New', 'replied' => 'Replied', 'closed' => 'Closed'];

    protected function casts(): array
    {
        return [
            'kinds' => 'array',
            'files' => 'array',
            'need_by' => 'date',
            'reply_by' => 'date',
            'confirmation_sent_at' => 'datetime',
            'staff_notified_at' => 'datetime',
        ];
    }

    public function name(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    /** The kinds of merch as the client picked them (labels). */
    public function kindLabels(): array
    {
        $all = config('website.projects.kinds');

        return array_values(array_map(fn ($k) => $all[$k] ?? $k, (array) $this->kinds));
    }
}
