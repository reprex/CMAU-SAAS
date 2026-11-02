<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\PositionRule;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * Range › Positions (Scott, 2 Nov 2026): curate the branding positions for a product
 * type, or for one product. A rule beats the automatic positions; saving marks the
 * live products it covers to publish again.
 */
class PositionRuleController extends Controller
{
    public function index(Request $request)
    {
        $types = Product::query()->where('tier', Product::TIER_LIVE)
            ->selectRaw("coalesce(promodata_type_name, '') as type, count(*) as n")->groupBy('type')->orderByDesc('n')->get();
        $rules = PositionRule::query()->whereNotNull('product_type')->get()->keyBy('product_type');
        $productRules = PositionRule::query()->whereNotNull('product_id')->count();
        $report = Cache::get('positions:report');
        $type = (string) $request->query('type', '');
        $rule = $type !== '' ? $rules->get($type) : null;
        $examples = $type !== '' ? Product::query()->where('tier', Product::TIER_LIVE)->where('promodata_type_name', $type)->orderBy('name')->limit(8)->get(['id', 'name', 'hero_image_url']) : collect();

        return view('range.positions', compact('types', 'rules', 'productRules', 'report', 'type', 'rule', 'examples'));
    }

    public function save(Request $request)
    {
        $data = $request->validate([
            'product_type' => 'nullable|string|max:191|required_without:product_id',
            'product_id' => 'nullable|integer|exists:products,id|required_without:product_type',
            'positions' => 'array|max:12',
            'positions.*.name' => 'nullable|string|max:60',
            'positions.*.view' => ['nullable', Rule::in(PositionRule::VIEWS)],
            'positions.*.width_mm' => 'nullable|integer|min:5|max:2000',
            'positions.*.height_mm' => 'nullable|integer|min:5|max:2000',
        ]);
        $positions = collect($data['positions'] ?? [])->filter(fn ($p) => trim((string) ($p['name'] ?? '')) !== '')
            ->map(fn ($p) => ['name' => trim($p['name']), 'view' => $p['view'] ?? 'front', 'width_mm' => $p['width_mm'] ?? null, 'height_mm' => $p['height_mm'] ?? null])->values()->all();
        $key = ! empty($data['product_id']) ? ['product_id' => (int) $data['product_id']] : ['product_type' => $data['product_type']];

        if ($positions === []) {
            PositionRule::query()->where($key)->delete();
            $message = 'Positions removed: the automatic positions apply again.';
        } else {
            PositionRule::query()->updateOrCreate($key, ['positions' => $positions, 'user_id' => $request->user()->id]);
            $message = 'Positions saved.';
        }
        $products = Product::query()->where('tier', Product::TIER_LIVE)
            ->when(isset($key['product_id']), fn ($q) => $q->whereKey($key['product_id']), fn ($q) => $q->where('promodata_type_name', $key['product_type']));
        $n = (clone $products)->count();
        $products->update(['range_changed_at' => now()]);
        AuditLog::record('positions.saved', null, $key + ['positions' => $positions]);

        return back()->with('status', $message." {$n} live ".($n === 1 ? 'product' : 'products').' marked to publish again.');
    }
}
