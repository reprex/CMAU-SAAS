<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\QuoteExtra;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Admin only: supplier standard freight (and later, the other supplier settings). */
class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('q'));
        $filter = $request->query('show', 'quoted');

        $suppliers = Supplier::query()
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('id', $search)))
            ->when($filter === 'quoted' && $search === '', fn ($q) => $q->where(fn ($q) => $q
                ->whereIn('id', fn ($s) => $s->select('supplier_id')->from('quote_items')->whereNotNull('supplier_id'))
                ->orWhereNotNull('freight_sell')->orWhere('web_flag', true)->orWhere('is_decorator', true)))
            ->when($filter === 'missing', fn ($q) => $q->whereNull('freight_sell')
                ->whereIn('id', fn ($s) => $s->select('supplier_id')->from('quote_items')->whereNotNull('supplier_id')))
            ->orderBy('name')
            ->paginate(50)->withQueryString();

        $missing = Supplier::query()->whereNull('freight_sell')
            ->whereIn('id', fn ($s) => $s->select('supplier_id')->from('quote_items')->whereNotNull('supplier_id'))->count();

        $lastCharged = QuoteExtra::query()->where('kind', 'freight')->where('tbc', false)->where('unit_price', '>', 0)
            ->whereIn('supplier_id', $suppliers->pluck('id'))
            ->selectRaw('supplier_id, max(unit_price) as hi, min(unit_price) as lo')
            ->groupBy('supplier_id')->get()->keyBy('supplier_id');

        return view('admin.suppliers.index', compact('suppliers', 'search', 'filter', 'missing', 'lastCharged'));
    }

    public function edit(Supplier $supplier)
    {
        $supplier->load(['freightRates.changedBy']);

        return view('admin.suppliers.edit', compact('supplier'));
    }

    public function update(Request $request, Supplier $supplier)
    {
        $data = $request->validate([
            'freight_cost' => 'nullable|numeric|min:0|max:99999',
            'freight_sell' => 'nullable|numeric|min:0|max:99999',
            'freight_basis' => ['required', Rule::in(array_keys(Supplier::FREIGHT_BASES))],
            'free_freight_over' => 'nullable|numeric|min:0|max:9999999',
            'freight_decorator_leg' => 'boolean',
            'is_decorator' => 'boolean',
            'freight_effective_from' => 'nullable|date',
        ]);
        $data['freight_decorator_leg'] = $request->boolean('freight_decorator_leg');
        $data['is_decorator'] = $request->boolean('is_decorator');

        $before = $supplier->only(array_keys($data));
        $supplier->update($data);

        $supplier->freightRates()->create([
            'freight_cost' => $supplier->freight_cost,
            'freight_sell' => $supplier->freight_sell,
            'freight_basis' => $supplier->freight_basis,
            'free_freight_over' => $supplier->free_freight_over,
            'freight_decorator_leg' => $supplier->freight_decorator_leg,
            'effective_from' => $supplier->freight_effective_from,
            'changed_by' => $request->user()->id,
        ]);
        AuditLog::record('supplier.freight_updated', $supplier, ['before' => $before, 'after' => $supplier->only(array_keys($data))]);

        return redirect()->route('admin.suppliers.edit', $supplier)->with('status', 'Freight saved. New quotes and recalculated quotes use it from now on.');
    }
}
