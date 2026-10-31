<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RangeBulkAction;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SyncRun;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Add a supplier's whole range (e.g. all of JB's Wear or Legend Life) to the
 * web range. Works from the products the sync has stored for that supplier, so
 * the screen shows when the supplier was last synced and offers a one-supplier
 * sync first. Adds go to the queue in batches of 25 (about 25 a minute), and
 * the action can be run again later to pick up new products.
 */
class RangeSupplierController extends Controller
{
    public function index(Request $request)
    {
        $supplier = $request->integer('supplier') ? Supplier::query()->find($request->integer('supplier')) : null;

        $types = collect();
        $lastSync = null;
        $running = null;
        if ($supplier) {
            // Per product type: how many are on the range already and how many could be added.
            $types = Product::query()
                ->where('supplier_id', $supplier->id)->where('discontinued', false)
                ->selectRaw("coalesce(promodata_type_name, '') as type_name")
                ->selectRaw('sum(case when tier = ? then 1 else 0 end) as live', [Product::TIER_LIVE])
                ->selectRaw('sum(case when tier <> ? then 1 else 0 end) as to_add', [Product::TIER_LIVE])
                ->groupBy('type_name')->orderBy('type_name')->get();

            $lastSync = SyncRun::query()->where('status', SyncRun::STATUS_COMPLETED)
                ->where(fn ($w) => $w->where('kind', SyncRun::KIND_FULL)
                    ->orWhere(fn ($w) => $w->where('kind', SyncRun::KIND_SUPPLIER)->where('supplier_id', $supplier->id)))
                ->latest('finished_at')->first();
            $running = SyncRun::query()->whereIn('status', [SyncRun::STATUS_PENDING, SyncRun::STATUS_RUNNING])->latest('id')->first();
        }

        return view('admin.range-supplier', [
            'supplier' => $supplier,
            'types' => $types,
            'lastSync' => $lastSync,
            'running' => $running,
            'suppliers' => Supplier::query()->whereHas('products')->withCount([
                'products as live_count' => fn ($w) => $w->where('tier', Product::TIER_LIVE),
                'products as available_count' => fn ($w) => $w->where('discontinued', false),
            ])->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'supplier_id' => 'required|integer|exists:suppliers,id',
            'types' => 'required|array|min:1',
            'types.*' => 'string|max:255',
        ]);
        $supplier = Supplier::query()->findOrFail($data['supplier_id']);
        $types = array_values(array_unique($data['types']));

        $ids = Product::query()
            ->where('supplier_id', $supplier->id)
            ->where('discontinued', false)
            ->where('tier', '<>', Product::TIER_LIVE)
            ->where(fn ($w) => $w->whereIn('promodata_type_name', array_diff($types, ['']))
                ->when(in_array('', $types, true), fn ($w) => $w->orWhereNull('promodata_type_name')->orWhere('promodata_type_name', '')))
            ->orderBy('id')->pluck('id')->all();

        foreach (array_chunk($ids, 25) as $chunk) {
            RangeBulkAction::dispatch('add', $chunk, $request->user()->id);
        }
        Cache::forget('range:counts');
        AuditLog::record('range.supplier_added', $supplier, ['queued' => count($ids), 'types' => $types]);

        $minutes = (int) ceil(count($ids) / 25);
        $message = count($ids) === 0
            ? "Nothing to add: every {$supplier->name} product of those types is already on the range."
            : sprintf('%s products from %s queued to add to the range. They are added in the background, about 25 a minute (roughly %d %s).',
                number_format(count($ids)), $supplier->name, $minutes, $minutes === 1 ? 'minute' : 'minutes');

        return redirect()->route('admin.range-supplier.index', ['supplier' => $supplier->id])->with('status', $message);
    }
}
