<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PublicHoliday;
use App\Models\QuoteExtra;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $closures = DB::table('supplier_closures')->where('supplier_id', $supplier->id)->orderBy('closed_from')->get()
            ->map(fn ($c) => trim($c->closed_from.' to '.$c->closed_to.' '.$c->note))->implode("\n");

        return view('admin.suppliers.edit', compact('supplier', 'closures'));
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
            'template_url_pattern' => ['nullable', 'string', 'max:500', 'regex:/^https:\/\/.+\{(code|CODE|appaid)\}/'],
            // Website orders (docs/website-checkout.md).
            'state' => ['nullable', Rule::in(PublicHoliday::STATES)],
            'stock_lead_days' => 'nullable|integer|min:0|max:60',
            'payment_terms' => ['nullable', Rule::in(array_keys(config('website.payment_terms.options')))],
            // Website samples (docs/website-checkout.md).
            'offers_samples' => 'boolean',
            'sample_freight' => 'nullable|numeric|min:0|max:9999',
            'sample_lead_days' => 'nullable|integer|min:0|max:60',
            // Lead times v1 (docs/lead-times.md).
            'transit_days' => 'nullable|integer|min:0|max:30',
            'ships_from' => ['nullable', Rule::in(['NZ'])],
            // Purchase orders (docs/purchase-orders.md).
            'orders_contact' => 'nullable|string|max:120',
            'orders_email' => 'nullable|email:rfc|max:190',
            'our_account_number' => 'nullable|string|max:60',
        ]);
        $closures = $this->closures((string) $request->input('closures', ''));
        if ($closures === null) {
            return back()->withInput()->withErrors(['closures' => 'Write each closure as "2026-12-18 to 2027-01-11 Christmas shutdown" (first and last day closed).']);
        }
        $data['offers_samples'] = $request->boolean('offers_samples');
        $data['state'] = $data['state'] ?? $supplier->state ?? config('website.lead_times.default_state');
        $data['payment_terms'] = $data['payment_terms'] ?? $supplier->payment_terms ?? config('website.payment_terms.default');
        $data['freight_decorator_leg'] = $request->boolean('freight_decorator_leg');
        $data['is_decorator'] = $request->boolean('is_decorator');

        $before = $supplier->only(array_keys($data));
        $supplier->update($data);
        DB::table('supplier_closures')->where('supplier_id', $supplier->id)->delete();
        foreach ($closures as $c) {
            DB::table('supplier_closures')->insert($c + ['supplier_id' => $supplier->id, 'source' => 'Admin › Suppliers ('.$request->user()->name.')', 'created_at' => now(), 'updated_at' => now()]);
        }

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

    /** "2026-12-18 to 2027-01-11 Christmas shutdown" per line; null when a line can't be read. */
    private function closures(string $text): ?array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (! preg_match('/^(\d{4}-\d{2}-\d{2})\s*(?:to|-|–)\s*(\d{4}-\d{2}-\d{2})\s*(.*)$/u', $line, $m) || $m[2] < $m[1]) {
                return null;
            }
            foreach ([$m[1], $m[2]] as $d) {
                [$y, $mo, $da] = array_map('intval', explode('-', $d));
                if (! checkdate($mo, $da, $y)) {
                    return null;
                }
            }
            $out[] = ['closed_from' => $m[1], 'closed_to' => $m[2], 'note' => mb_substr(trim($m[3]), 0, 255) ?: null];
        }

        return $out;
    }
}
