<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Quote;
use App\Models\QuoteExtra;
use App\Quotes\QuoteCalculator;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Custom products, services, information lines, and freight adjustments. */
class QuoteExtraController extends Controller
{
    public function __construct(private readonly QuoteCalculator $calculator) {}

    public function store(Request $request, Quote $quote)
    {
        $data = $this->validated($request, true);
        $extra = $quote->extras()->create($data + ['position' => $quote->nextPosition()]);
        $this->changed($quote, 'quote_extra.added', $extra);

        return back()->with('status', QuoteExtra::KINDS[$extra->kind].' added.');
    }

    public function update(Request $request, QuoteExtra $extra)
    {
        $data = $this->validated($request, ! $extra->auto);
        if ($extra->auto) {
            // Staff may adjust an automatic freight line's price; it then stops following the supplier setting.
            $data = ['unit_price' => $data['unit_price'], 'overridden' => true, 'tbc' => false];
        }
        $extra->update($data);
        $this->changed($extra->quote, 'quote_extra.updated', $extra, $extra->getChanges());

        return back()->with('status', 'Line saved.');
    }

    public function reset(QuoteExtra $extra)
    {
        abort_unless($extra->auto, 404);
        $extra->update(['overridden' => false]);
        $this->changed($extra->quote, 'quote_extra.freight_reset', $extra);

        return back()->with('status', 'Freight follows the supplier setting again.');
    }

    public function destroy(QuoteExtra $extra)
    {
        abort_if($extra->auto, 422, 'Automatic freight lines cannot be removed; set the price to 0 to waive it.');
        $quote = $extra->quote;
        $extra->delete();
        $this->changed($quote, 'quote_extra.removed', $extra);

        return back()->with('status', 'Line removed.');
    }

    private function validated(Request $request, bool $full): array
    {
        $data = $request->validate([
            'kind' => [$full ? 'required' : 'nullable', Rule::in(array_keys(QuoteExtra::KINDS))],
            'description' => [$full ? 'required' : 'nullable', 'string', 'max:1024'],
            'quantity' => 'nullable|numeric|min:0|max:1000000',
            'unit_price' => 'nullable|numeric|min:0|max:9999999',
            'unit_cost' => 'nullable|numeric|min:0|max:9999999',
            'supplier_id' => 'nullable|integer|exists:suppliers,id',
            'image_url' => ['nullable', 'url:https', 'max:1024'],
        ]);
        $data['quantity'] = $data['quantity'] ?? 1;
        $data['unit_price'] = $data['unit_price'] ?? 0;

        return $data;
    }

    private function changed(Quote $quote, string $action, QuoteExtra $extra, array $changes = []): void
    {
        $quote->update(['edited_at' => $quote->edited_at ?? now()]);
        $this->calculator->recalculate($quote->refresh());
        AuditLog::record($action, $extra, $changes);
    }
}
