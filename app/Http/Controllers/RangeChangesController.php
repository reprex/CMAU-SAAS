<?php

namespace App\Http\Controllers;

use App\Catalogue\RangeChanges;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\RangeSuggestion;
use Illuminate\Http\Request;

/**
 * Range › Changes to vet (docs/range.md): suppliers' new products to approve onto the range, and
 * discontinued products with the open quotes that used them, to offer those clients a substitute.
 */
class RangeChangesController extends Controller
{
    public const VIEWS = ['ready' => 'Ready to approve', 'check' => 'Needs a look', 'discontinued' => 'Discontinued', 'done' => 'Done'];

    public function index(Request $request, RangeChanges $changes)
    {
        $view = array_key_exists($request->query('view'), self::VIEWS) ? $request->query('view') : 'ready';
        $base = RangeSuggestion::query();
        $counts = [
            'ready' => (clone $base)->where('kind', 'new')->where('status', 'pending')->where('ready', true)->count(),
            'check' => (clone $base)->where('kind', 'new')->where('status', 'pending')->where('ready', false)->count(),
            'discontinued' => (clone $base)->where('kind', 'discontinued')->where('status', 'pending')->count(),
            'preparing' => (clone $base)->where('status', 'preparing')->count(),
        ];
        $rows = RangeSuggestion::query()->with(['product.webPrice', 'product.supplier:id,name'])
            ->when($view === 'ready', fn ($q) => $q->where('kind', 'new')->where('status', 'pending')->where('ready', true))
            ->when($view === 'check', fn ($q) => $q->where('kind', 'new')->where('status', 'pending')->where('ready', false))
            ->when($view === 'discontinued', fn ($q) => $q->where('kind', 'discontinued')->where('status', 'pending'))
            ->when($view === 'done', fn ($q) => $q->whereIn('status', ['approved', 'declined'])->orderByDesc('decided_at'))
            ->orderBy('supplier_id')->orderBy('id')->paginate(100)->withQueryString();

        $quotes = collect();
        $subs = collect();
        if ($view === 'discontinued') {
            $quotes = $changes->quotesFor($rows->pluck('product_id')->all());
            // Quoted products first: those clients need a call.
            $rows->setCollection($rows->getCollection()->sortByDesc(fn ($r) => $quotes->has($r->product_id) ? 1 : 0)->values());
            $subs = Product::query()->with('webPrice')->whereKey($rows->flatMap(fn ($r) => $r->checks['substitutes'] ?? [])->unique()->all())->get()->keyBy('id');
        }

        return view('range.changes', compact('view', 'counts', 'rows', 'quotes', 'subs'));
    }

    public function decide(Request $request, RangeChanges $changes)
    {
        $data = $request->validate([
            'action' => 'required|in:approve,approve_ready,decline,done',
            'ids' => 'array', 'ids.*' => 'integer',
        ]);
        $ids = $data['ids'] ?? [];
        if ($data['action'] === 'approve_ready') {
            $ids = RangeSuggestion::query()->where('kind', 'new')->where('status', 'pending')->where('ready', true)->pluck('id')->all();
        }
        if ($ids === []) {
            return back()->with('status', 'Tick at least one product.');
        }
        $n = match ($data['action']) {
            'approve', 'approve_ready' => $changes->approve($ids, $request->user()->id),
            'decline' => $changes->close($ids, RangeSuggestion::DECLINED, $request->user()->id),
            'done' => $changes->close($ids, RangeSuggestion::APPROVED, $request->user()->id),
        };
        AuditLog::record('range.changes_'.$data['action'], null, ['count' => $n, 'ids' => array_slice($ids, 0, 200)]);

        return back()->with('status', match ($data['action']) {
            'approve', 'approve_ready' => "{$n} products approved: they are being added to the range (about 25 a minute), then the publisher sends them to Shopify.",
            'decline' => "{$n} products declined: they won't be suggested again.",
            'done' => "{$n} marked as dealt with.",
        });
    }

    public function recheck(RangeSuggestion $suggestion, RangeChanges $changes)
    {
        $changes->recheck($suggestion);

        return back()->with('status', 'Checked again.');
    }
}
