<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RepriceProduct;
use App\Models\AuditLog;
use App\Models\DecTypeCode;
use App\Models\PriceOption;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin only: TOD's DecTypeCode table. Every supplier price-line description
 * is classified once as decoration (10), accessory (11), undecorated (12) or
 * hidden (23); new descriptions from Promodata arrive unclassified.
 */
class DecorationTypeController extends Controller
{
    public const TYPES = [10 => 'Decoration', 11 => 'Accessory', 12 => 'Undecorated', 23 => 'Hidden'];

    public function index(Request $request)
    {
        $show = $request->query('show', 'todo');
        $q = trim((string) $request->query('q'));

        $codes = DecTypeCode::query()
            ->when($show === 'todo', fn ($w) => $w->whereNull('type_code'))
            ->when($q !== '', fn ($w) => $w->where(fn ($w) => $w->where('code', 'like', "%{$q}%")->orWhere('full_description', 'like', "%{$q}%")))
            ->orderBy('code')
            ->paginate(50)->withQueryString();

        $usage = PriceOption::query()->whereIn('code', $codes->pluck('code'))
            ->whereIn('product_id', Product::query()->where('tier', Product::TIER_LIVE)->select('id'))
            ->selectRaw('code, count(distinct product_id) as n')->groupBy('code')->pluck('n', 'code');

        return view('admin.decoration-types.index', [
            'codes' => $codes, 'show' => $show, 'q' => $q, 'usage' => $usage,
            'todo' => DecTypeCode::query()->whereNull('type_code')->count(),
        ]);
    }

    public function update(Request $request, DecTypeCode $decorationType)
    {
        $data = $request->validate([
            'type_code' => ['nullable', Rule::in(array_keys(self::TYPES))],
            'overwrite_description' => 'nullable|string|max:255',
        ]);
        $before = $decorationType->only(['type_code', 'overwrite_description']);
        $decorationType->update([
            'type_code' => $data['type_code'] ?? null,
            'overwrite_description' => ($data['overwrite_description'] ?? '') ?: null,
        ]);
        AuditLog::record('decoration_type.updated', $decorationType, ['before' => $before, 'after' => $decorationType->only(['type_code', 'overwrite_description'])]);

        $ids = PriceOption::query()->where('code', $decorationType->code)
            ->whereIn('product_id', Product::query()->fullDetail()->select('id'))
            ->distinct()->pluck('product_id');
        $ids->each(fn ($id) => RepriceProduct::dispatch((int) $id));

        return back()->with('status', "\"{$decorationType->code}\" saved. ".($ids->count() ? "{$ids->count()} products using it are being repriced." : ''));
    }
}
