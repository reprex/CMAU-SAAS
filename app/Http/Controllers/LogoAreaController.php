<?php

namespace App\Http\Controllers;

use App\Catalogue\LogoAreaLearner;
use App\Catalogue\PrintAreas;
use App\Models\AuditLog;
use App\Models\PrintArea;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Logo areas (docs/logo-preview.md): staff vet a sample of about 100 products
 * (drag the CrowdMerch logo into place, approve); the corrections are learned
 * and applied to the rest of the web range, which can be spot-checked.
 */
class LogoAreaController extends Controller
{
    public const VIEWS = ['sample' => 'Sample to vet', 'learned' => 'Learned', 'done' => 'Approved', 'groups' => 'What was learned'];

    public function index(Request $request, PrintAreas $areas, LogoAreaLearner $learner)
    {
        $view = array_key_exists($request->query('view'), self::VIEWS) ? $request->query('view') : 'sample';
        $counts = [
            'sample' => PrintArea::query()->where('sample', true)->whereNull('approved_at')->count(),
            'learned' => PrintArea::query()->where('source', 'learned')->whereNull('approved_at')->count(),
            'done' => PrintArea::query()->whereNotNull('approved_at')->count(),
        ];
        $live = Product::query()->where('tier', Product::TIER_LIVE)->where('discontinued', false)->whereNotNull('hero_image_url')->count();

        if ($view === 'groups') {
            $groups = Cache::remember('logo-areas:groups', 60, fn () => $learner->learnSummary());
            $names = Supplier::query()->pluck('name', 'id');

            return view('range.logo-areas', compact('view', 'counts', 'live', 'groups', 'names') + ['cards' => collect(), 'products' => null, 'learner' => $learner]);
        }

        $q = PrintArea::query()->with('approver:id,name')
            ->when($view === 'sample', fn ($w) => $w->where('sample', true)->whereNull('approved_at'))
            ->when($view === 'learned', fn ($w) => $w->where('source', 'learned')->whereNull('approved_at')->orderByRaw("case learned_from when 'all' then 0 when 'type' then 1 else 2 end"))
            ->when($view === 'done', fn ($w) => $w->whereNotNull('approved_at')->latest('approved_at'))
            ->orderBy('group_key');
        $products = $q->paginate(24)->withQueryString();
        $byId = Product::query()->with(['supplier:id,name', 'images', 'colours', 'override'])->findMany($products->pluck('product_id'))->keyBy('id');
        $cards = $products->getCollection()->map(fn (PrintArea $a) => ['area' => $a, 'product' => $byId[$a->product_id] ?? null])->filter(fn ($c) => $c['product']);

        return view('range.logo-areas', compact('view', 'counts', 'live', 'cards', 'products') + ['groups' => null]);
    }

    public function store(Request $request, Product $product, PrintAreas $areas)
    {
        $data = $request->validate([
            'left' => 'required|numeric|min:0|max:100',
            'top' => 'required|numeric|min:0|max:100',
            'width' => 'required|numeric|min:1|max:100',
        ]);
        $area = $areas->approve($product, (float) $data['left'], (float) $data['top'], (float) $data['width'], $request->user()->id);
        AuditLog::record('range.logo_area', $product, ['left' => $area->left, 'top' => $area->top, 'width' => $area->width]);
        Cache::forget('logo-areas:groups');

        return $request->expectsJson()
            ? response()->json(['ok' => true])
            : back()->with('status', "Logo area approved for {$product->name}.");
    }

    public function sample(Request $request, LogoAreaLearner $learner)
    {
        $size = max(10, min(200, $request->integer('size', 100)));
        $n = $learner->pickSample($size);

        return redirect()->route('range.logo-areas', ['view' => 'sample'])->with('status', "{$n} products added to the sample to vet.");
    }

    public function apply(LogoAreaLearner $learner)
    {
        $r = $learner->learn();
        Cache::forget('logo-areas:dirty');
        Cache::forget('logo-areas:groups');

        return redirect()->route('range.logo-areas', ['view' => 'learned'])->with('status', "Applied what was learned to {$r['applied']} products.");
    }
}
