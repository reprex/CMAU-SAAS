<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\PriceOption;
use App\Models\Product;
use App\Models\ProductColour;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Quotes\ItemPricer;
use App\Quotes\ItemSplitter;
use App\Quotes\QuoteCalculator;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class QuoteItemController extends Controller
{
    public function __construct(
        private readonly ItemPricer $pricer,
        private readonly ItemSplitter $splitter,
        private readonly QuoteCalculator $calculator,
    ) {}

    /** Add a product from the SaaS range. */
    public function store(Request $request, Quote $quote)
    {
        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'quantity' => 'required|integer|min:1|max:1000000',
            'colour' => 'nullable|string|max:190',
            'decoration_option_id' => 'nullable|string|max:190',
        ]);
        $product = Product::with('supplier', 'detail')->findOrFail($data['product_id']);

        $item = new QuoteItem([
            'quote_id' => $quote->id,
            'position' => $quote->nextPosition(),
            'stage' => 'separated',
            'product_id' => $product->id,
            'supplier_id' => $product->supplier_id,
            'supplier_name' => $product->supplier?->name,
            'product_code' => $product->code,
            'name' => $product->name,
            'colour' => $data['colour'] ?? null,
            'image_url' => $this->image($product, $data['colour'] ?? null),
            'description' => $product->detail?->description,
            'quantity' => $data['quantity'],
            'decoration_option_id' => $data['decoration_option_id'] ?? null,
        ]);
        $this->pricer->attachDefaults($item);
        if ($item->decoration_option_id) {
            $option = PriceOption::query()->where('option_id', $item->decoration_option_id)->first();
            $item->decoration_name = $option?->display_description ?: $option?->description;
        }
        $priced = $this->pricer->reprice($item);
        $item->save();

        $this->changed($quote, 'quote_item.added', $item);

        return back()->with('status', $priced ? "{$item->name} added." : "{$item->name} added. It has no catalogue prices, so enter them by hand.");
    }

    /** Another quantity of the same product and decoration (comparison options). */
    public function duplicate(Request $request, QuoteItem $item)
    {
        $data = $request->validate(['quantity' => 'required|integer|min:1|max:1000000']);
        $copy = $item->replicate(['stage', 'stock_checked_at']);
        $copy->fill(['quantity' => $data['quantity'], 'position' => $item->quote->nextPosition(), 'stage' => $item->stage === 'sku' ? 'separated' : $item->stage]);
        $this->pricer->reprice($copy);
        $copy->save();
        $this->changed($item->quote, 'quote_item.duplicated', $copy);

        return back()->with('status', "Added {$data['quantity']} × {$item->name}.");
    }

    public function update(Request $request, QuoteItem $item)
    {
        $data = $request->validate([
            'name' => 'required|string|max:190',
            'colour' => 'nullable|string|max:190',
            'quantity' => 'required|integer|min:1|max:1000000',
            'description' => 'nullable|string|max:5000',
            'notes' => 'nullable|string|max:2000',
            'image_url' => ['nullable', 'url:https', 'max:1024'],
            'product_unit_price' => 'required|numeric|min:0',
            'product_setup_price' => 'required|numeric|min:0',
            'product_unit_cost' => 'nullable|numeric|min:0',
            'product_setup_cost' => 'nullable|numeric|min:0',
            'decoration_name' => 'nullable|string|max:190',
            'decoration_unit_price' => 'required|numeric|min:0',
            'decoration_setup_price' => 'required|numeric|min:0',
            'decoration_unit_cost' => 'nullable|numeric|min:0',
            'decoration_setup_cost' => 'nullable|numeric|min:0',
            'decoration_option_id' => 'nullable|string|max:190',
            'reprice' => 'boolean',
        ]);
        $reprice = $request->boolean('reprice');
        unset($data['reprice']);

        $optionChanged = ($data['decoration_option_id'] ?? null) !== $item->decoration_option_id;
        $item->fill($data);
        if ($optionChanged && $item->decoration_option_id) {
            $option = PriceOption::query()->where('option_id', $item->decoration_option_id)->first();
            $item->decoration_name = $option?->display_description ?: ($option?->description ?? $item->decoration_name);
            $reprice = true;
        }
        $note = '';
        if ($reprice && ! $this->pricer->reprice($item)) {
            $note = ' There are no catalogue prices for this item, so your prices were kept.';
        }
        $item->save();
        $this->changed($item->quote, 'quote_item.updated', $item, $item->getChanges());

        return back()->with('status', "{$item->name} saved.{$note}");
    }

    public function destroy(QuoteItem $item)
    {
        $quote = $item->quote;
        $item->delete();
        $this->changed($quote, 'quote_item.removed', $item);

        return back()->with('status', "{$item->name} removed.");
    }

    public function separate(QuoteItem $item)
    {
        $this->splitter->separate($item);
        $item->quote->update(['edited_at' => $item->quote->edited_at ?? now()]);

        return back();
    }

    public function combine(QuoteItem $item)
    {
        $this->splitter->combine($item);

        return back();
    }

    /** JSON: Promodata's live SKU list for this item. */
    public function skuList(QuoteItem $item)
    {
        return response()->json($this->splitter->skuList($item));
    }

    public function saveSkus(Request $request, QuoteItem $item)
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['list', 'manual'])],
            'rows' => 'array|max:500',
            'rows.*.colour' => 'nullable|string|max:190',
            'rows.*.size' => 'nullable|string|max:32',
            'rows.*.quantity' => 'nullable|integer|min:0|max:1000000',
            'rows.*.supplier_sku' => 'nullable|string|max:128',
            'rows.*.stock_on_hand' => 'nullable|integer',
        ]);
        $result = $this->splitter->saveSkus($item, $data['rows'] ?? [], $data['mode']);
        $this->changed($item->quote, 'quote_item.sizes', $item);

        $message = 'Sizes saved.';
        if ($result['quantity_changed']) {
            $message .= " Quantity is now {$item->quantity}".($result['repriced'] ? ' and the prices were updated for it.' : '; check the prices, as there are no catalogue prices to update from.');
        }

        return back()->with('status', $message);
    }

    private function changed(Quote $quote, string $action, QuoteItem $item, array $changes = []): void
    {
        $quote->update(['edited_at' => $quote->edited_at ?? now()]);
        $this->calculator->recalculate($quote->refresh());
        AuditLog::record($action, $item, $changes);
    }

    private function image(Product $product, ?string $colour): ?string
    {
        if ($colour) {
            $url = ProductColour::query()->where('product_id', $product->id)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($colour)])->value('image_url');
            if ($url) {
                return $url;
            }
        }

        return $product->hero_image_url;
    }
}
