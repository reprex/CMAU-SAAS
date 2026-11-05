<?php

namespace App\Http\Controllers;

use App\Models\BrandingTemplate;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Range › Template reads: what Claude read from each branding template, with every print area drawn on the photo
 * it was placed on, to judge the reading before more are read (Scott, 6 Nov 2026). Read-only.
 */
class TemplateReviewController extends Controller
{
    public function index(Request $request): View
    {
        $supplier = $request->integer('supplier') ?: null;
        $q = BrandingTemplate::query()->where('status', 'read')
            ->when($supplier, fn ($q) => $q->whereHas('product', fn ($p) => $p->where('supplier_id', $supplier)));
        $templates = (clone $q)->with(['product:id,name,code,supplier_id,hero_image_url', 'positions'])
            ->when($request->query('only') === 'boxes', fn ($q) => $q->whereHas('positions', fn ($p) => $p->whereNotNull('image_url')))
            ->latest('read_at')->paginate(24)->withQueryString();
        $suppliers = Supplier::query()->whereIn('id', BrandingTemplate::query()->where('status', 'read')
            ->join('products', 'products.id', '=', 'branding_templates.product_id')->distinct()->pluck('products.supplier_id'))->orderBy('name')->pluck('name', 'id');

        return view('range.template-reads', ['templates' => $templates, 'suppliers' => $suppliers, 'supplier' => $supplier, 'total' => (clone $q)->count()]);
    }
}
