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

        // Live-range use per description, for the "on the range" filter (most used first).
        $live = fn () => PriceOption::query()->whereIn('product_id', Product::query()->where('tier', Product::TIER_LIVE)->where('discontinued', false)->select('id'))
            ->selectRaw('code, count(distinct product_id) as live_n')->groupBy('code');

        $codes = DecTypeCode::query()->select('dec_type_codes.*')
            ->when($show === 'todo', fn ($w) => $w->whereNull('type_code'))
            ->when($show === 'live', fn ($w) => $w->whereNull('type_code')
                ->joinSub($live(), 'lu', 'lu.code', '=', 'dec_type_codes.code')->orderByDesc('lu.live_n'))
            ->when($q !== '', fn ($w) => $w->where(fn ($w) => $w->where('dec_type_codes.code', 'like', "%{$q}%")->orWhere('full_description', 'like', "%{$q}%")))
            ->orderBy('dec_type_codes.code')
            ->paginate(50)->withQueryString();

        $usage = PriceOption::query()->whereIn('code', $codes->pluck('code'))
            ->whereIn('product_id', Product::query()->where('tier', Product::TIER_LIVE)->select('id'))
            ->selectRaw('code, count(distinct product_id) as n')->groupBy('code')->pluck('n', 'code');

        // Context for deciding: up to three products using each description (on the range first),
        // with that line's prices and the first product's other price lines.
        $examples = [];
        $siblings = [];
        foreach ($codes as $c) {
            $rows = PriceOption::query()->join('products as p', 'p.id', '=', 'price_options.product_id')
                ->where('price_options.code', $c->code)
                ->orderByRaw("case when p.tier = 'live' and p.discontinued = 0 then 0 when p.tier = 'live' then 1 else 2 end")
                ->orderBy('p.id')->limit(3)
                ->get(['price_options.product_id', 'price_options.kind', 'price_options.price_string', 'price_options.setup_price', 'p.name', 'p.code as product_code', 'p.tier', 'p.promodata_type_name']);
            $examples[$c->code] = $rows;
            if ($first = $rows->first()) {
                $siblings[$c->code] = PriceOption::query()->where('product_id', $first->product_id)->where('code', '!=', $c->code)
                    ->orderBy('position')->limit(8)->get(['description', 'type_code', 'price_string']);
            }
        }

        return view('admin.decoration-types.index', [
            'codes' => $codes, 'show' => $show, 'q' => $q, 'usage' => $usage, 'examples' => $examples, 'siblings' => $siblings,
            'todo' => DecTypeCode::query()->whereNull('type_code')->count(),
            'todoLive' => DecTypeCode::query()->whereNull('type_code')->joinSub($live(), 'lu', 'lu.code', '=', 'dec_type_codes.code')->count(),
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
