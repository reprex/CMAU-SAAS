<?php

namespace App\Http\Controllers;

use App\Catalogue\SubstituteFinder;
use App\Models\Product;
use App\Stock\PromodataStock;
use Illuminate\Http\Request;

/** Substitute product finder (all staff). */
class SubstituteController extends Controller
{
    public function lookup(Request $request)
    {
        $id = (int) $request->query('product');
        if ($id > 0 && Product::query()->whereKey($id)->exists()) {
            return redirect()->route('range.substitutes', $id);
        }

        return redirect()->route('range.index')->withErrors(['product' => $id ? "No product {$id} in the catalogue yet." : 'Enter an APPAID.']);
    }

    public function show(Request $request, Product $product, SubstituteFinder $finder)
    {
        $filters = $request->validate([
            'scope' => 'nullable|in:type,subcategory,any',
            'max_cost' => 'nullable|numeric|min:0',
            'max_moq' => 'nullable|integer|min:1',
            'max_lead' => 'nullable|integer|min:1|max:365',
            'need' => 'nullable|integer|min:1|max:10000000',
            'other_suppliers' => 'nullable|boolean',
        ]);
        $filters['scope'] ??= 'type';

        $product->load('supplier:id,name');
        $results = $finder->find($product, $filters + ['limit' => 30]);

        return view('range.substitutes', [
            'ref' => $product,
            'results' => $results,
            'filters' => $filters,
            'finder' => $finder,
        ]);
    }

    /** Live stock summary for one product (JSON, filled in after the page loads). */
    public function stock(Product $product, PromodataStock $stock)
    {
        if ($product->source !== 'promodata' || ! $product->can_check_stock) {
            return response()->json(['ok' => false, 'message' => 'No live stock from this supplier']);
        }

        $result = $stock->rows($product->id);
        if (! $result['ok']) {
            return response()->json(['ok' => false, 'message' => $result['message']]);
        }

        $byColour = [];
        foreach ($result['rows'] as $row) {
            $key = $row['colour'] ?? '–';
            $byColour[$key] = ($byColour[$key] ?? 0) + (int) $row['qty'];
        }
        arsort($byColour);

        return response()->json([
            'ok' => true,
            'total' => array_sum($byColour),
            'best_colour' => array_key_first($byColour),
            'best_qty' => $byColour === [] ? 0 : reset($byColour),
            'colours' => count($byColour),
        ]);
    }
}
