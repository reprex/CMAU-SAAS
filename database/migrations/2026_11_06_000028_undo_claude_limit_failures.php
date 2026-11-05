<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 5–6 Nov 2026: when the Claude API spend limit was reached, templates were marked failed and photos "could not
 * load". Neither was the item's fault: templates go back in the queue, and those photo checks are forgotten so
 * they are checked again once the limit resets (App\Support\ClaudeLimit now pauses instead).
 */
return new class extends Migration
{
    public function up(): void
    {
        $limit = fn ($q, string $col) => $q->where(fn ($w) => $w->where($col, 'like', '%usage limit%')->orWhere($col, 'like', '%credit balance%')
            ->orWhere($col, 'like', '%rate limit%')->orWhere($col, 'like', '%regain access%')->orWhere($col, 'like', '%overloaded%'));
        $limit(DB::table('branding_templates')->where('status', 'failed'), 'message')->update(['status' => 'new', 'message' => 'Waiting: Claude API limit']);
        $limit(DB::table('photo_brandings')->whereNull('branding'), 'note')->delete();
    }

    public function down(): void {}
};
