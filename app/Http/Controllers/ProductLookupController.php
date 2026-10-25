<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Quotes\ItemPricer;
use Illuminate\Http\Request;

/** JSON look-ups for the quote editor's "add from range" panel. */
class ProductLookupController extends Controller
{
    public function search(Request $request)
    {
        $q = trim((string) $request->query('q'));
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        $products = Product::query()
            ->with('supplier:id,name')
            ->where('discontinued', false)
            ->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('code', 'like', "%{$q}%")
                ->when(ctype_digit($q), fn ($w) => $w->orWhere('id', (int) $q)))
            ->orderByRaw("CASE tier WHEN 'live' THEN 0 WHEN 'active' THEN 1 ELSE 2 END")
            ->orderBy('name')
            ->limit(20)
            ->get();

        return response()->json($products->map(fn (Product $p) => [
            'id' => $p->id,
            'name' => $p->name,
            'code' => $p->code,
            'supplier' => $p->supplier?->name,
            'tier' => $p->tier,
            'image' => $p->hero_image_url,
        ]));
    }

    public function options(Product $product, ItemPricer $pricer)
    {
        return response()->json([
            'colours' => $product->colours()->pluck('name')->unique()->values(),
            'decorations' => $pricer->decorationOptions($product->id)->map(fn ($o) => [
                'id' => $o->option_id,
                'name' => $o->display_description ?: $o->description,
                'setup' => (float) $o->setup_price,
                'prices' => $o->price_string,
            ])->values(),
        ]);
    }
}
