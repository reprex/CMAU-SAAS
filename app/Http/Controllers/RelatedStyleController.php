<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\RelatedStyle;
use Illuminate\Http\Request;

/**
 * Range › product › Related styles: staff vet which other styles (womens, kids,
 * long sleeve...) of the same supplier can share a website order and its print run.
 */
class RelatedStyleController extends Controller
{
    public function store(Request $request, Product $product)
    {
        $data = $request->validate(['related_product_id' => 'required|integer|min:1', 'label' => 'nullable|string|max:60']);
        $related = Product::query()->find($data['related_product_id']);
        if ($related === null || $related->id === $product->id) {
            return back()->withErrors(['related_product_id' => 'Enter the product id (APPAID) of another product.']);
        }
        if ($related->supplier_id !== $product->supplier_id) {
            return back()->withErrors(['related_product_id' => 'Related styles must come from the same supplier.']);
        }
        RelatedStyle::query()->updateOrCreate(
            ['product_id' => $product->id, 'related_product_id' => $related->id],
            ['label' => $data['label'] ?? null, 'position' => (int) RelatedStyle::query()->where('product_id', $product->id)->max('position') + 1],
        );
        AuditLog::record('range.related_style_added', $product, ['related' => $related->id]);

        return back()->with('status', "{$related->name} added as a related style.");
    }

    public function destroy(Product $product, RelatedStyle $style)
    {
        abort_unless($style->product_id === $product->id, 404);
        $style->delete();
        AuditLog::record('range.related_style_removed', $product, ['related' => $style->related_product_id]);

        return back()->with('status', 'Related style removed.');
    }
}
