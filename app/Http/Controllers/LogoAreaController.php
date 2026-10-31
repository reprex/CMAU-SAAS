<?php

namespace App\Http\Controllers;

use App\Catalogue\PrintAreas;
use App\Models\AuditLog;
use App\Models\PrintArea;
use App\Models\Product;
use Illuminate\Http\Request;

/**
 * Vet where the logo goes on each web-range product's hero image
 * (docs/logo-preview.md): the CrowdMerch logo is placed from a Claude
 * suggestion or the product-type default; staff drag and resize it, then approve.
 */
class LogoAreaController extends Controller
{
    public const VIEWS = ['todo' => 'To vet', 'done' => 'Approved'];

    public function index(Request $request, PrintAreas $areas)
    {
        $view = array_key_exists($request->query('view'), self::VIEWS) ? $request->query('view') : 'todo';
        $approvedIds = PrintArea::query()->whereNotNull('approved_at')->pluck('product_id');
        $base = Product::query()->where('tier', Product::TIER_LIVE)->where('discontinued', false)->whereNotNull('hero_image_url');
        $counts = ['todo' => (clone $base)->whereNotIn('id', $approvedIds)->count(), 'done' => (clone $base)->whereIn('id', $approvedIds)->count()];
        $products = (clone $base)->when($view === 'todo', fn ($q) => $q->whereNotIn('id', $approvedIds), fn ($q) => $q->whereIn('id', $approvedIds))
            ->with(['supplier:id,name', 'images', 'colours', 'override'])
            ->when($request->integer('supplier'), fn ($q, $s) => $q->where('supplier_id', $s))
            ->orderBy('promodata_type_name')->orderBy('name')->paginate(24)->withQueryString();

        $cards = $products->getCollection()->map(fn (Product $p) => [
            'product' => $p, 'hero' => $areas->heroOf($p), 'area' => $areas->areaFor($p), 'stored' => $areas->find($p),
        ]);

        return view('range.logo-areas', compact('view', 'counts', 'products', 'cards'));
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

        return $request->expectsJson()
            ? response()->json(['ok' => true])
            : back()->with('status', "Logo area approved for {$product->name}.");
    }
}
