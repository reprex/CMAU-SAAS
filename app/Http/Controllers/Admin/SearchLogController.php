<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SearchLog;
use Illuminate\Http\Request;

/**
 * What visitors typed into the step 1 search, how each was answered (keyword,
 * Claude, cached or blocked) and why. Used to tune keyword search and to see
 * whether Claude is earning its cost (docs/website-search.md).
 */
class SearchLogController extends Controller
{
    public function index(Request $request)
    {
        $days = max(1, min(90, $request->integer('days', 7)));
        $since = now()->subDays($days);
        $mode = $request->string('mode')->toString();
        $base = SearchLog::query()->where('created_at', '>=', $since);

        $byMode = (clone $base)->selectRaw('mode, count(*) as n, sum(tokens_in) as tin, sum(tokens_out) as tout')->groupBy('mode')->get()->keyBy('mode');
        $byReason = (clone $base)->whereNotNull('reason')->selectRaw('mode, reason, count(*) as n')->groupBy('mode', 'reason')->orderByDesc('n')->get();
        $visitors = (clone $base)->distinct()->count('visitor');
        $agents = (clone $base)->where('agent', true)->count();
        $empty = (clone $base)->whereIn('mode', ['keyword', 'ai', 'cached'])->where('result_ids', '[]')->count();

        $rows = (clone $base)->when(in_array($mode, SearchLog::MODES, true), fn ($q) => $q->where('mode', $mode))
            ->latest('id')->limit(200)->get();

        return view('admin.search-log', compact('days', 'mode', 'byMode', 'byReason', 'visitors', 'agents', 'empty', 'rows'));
    }
}
