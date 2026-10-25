<?php

namespace App\Quotes;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductColour;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\ShopifyDraftOrder;
use App\Models\Supplier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Turns Shopify draft orders into quotes.
 *
 * Every draft order is stored as received. Drafts with an email address are
 * quote requests and become quotes; drafts without one are anonymous wish
 * lists, counted in the inbox but not turned into quotes (most are automated
 * traffic) unless staff open one.
 *
 * Updates from Shopify refresh a quote's items only until staff first change
 * the quote (quotes.edited_at); after that the quote is left alone and the
 * update is noted on it.
 */
class DraftOrderIntake
{
    public function __construct(
        private readonly DraftOrderParser $parser,
        private readonly QuoteNumbers $numbers,
        private readonly ItemPricer $pricer,
        private readonly QuoteCalculator $calculator,
    ) {}

    /** Store (or refresh) the raw draft order, then process it. */
    public function receive(array $raw): ShopifyDraftOrder
    {
        $parsed = $this->parser->parse($raw);

        $draft = ShopifyDraftOrder::query()->firstOrNew(['id' => $parsed['id']]);
        $updatedAt = $parsed['updated_at'] ? Carbon::parse($parsed['updated_at']) : null;
        if ($draft->exists && $updatedAt && $draft->shopify_updated_at && $updatedAt->lte($draft->shopify_updated_at) && $draft->processed_at) {
            return $draft; // nothing newer
        }

        $draft->fill([
            'name' => $parsed['name'],
            'status' => $parsed['status'],
            'email' => $parsed['email'],
            'anonymous' => $parsed['email'] === null,
            'line_count' => count($parsed['lines']),
            'payload' => json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'shopify_created_at' => $parsed['created_at'] ? Carbon::parse($parsed['created_at']) : null,
            'shopify_updated_at' => $updatedAt,
            'processed_at' => null,
            'error' => null,
        ])->save();

        $this->process($draft);

        return $draft->refresh();
    }

    public function process(ShopifyDraftOrder $draft, bool $includeAnonymous = false): ?Quote
    {
        try {
            $parsed = $this->parser->parse($draft->data());
            $quote = Quote::withTrashed()->where('shopify_draft_order_id', $draft->id)->first();

            if ($quote === null && $draft->anonymous && ! $includeAnonymous) {
                $draft->update(['processed_at' => now()]);

                return null;
            }

            $quote = DB::transaction(fn () => $quote
                ? $this->refresh($quote, $parsed)
                : $this->create($parsed, $draft));

            $draft->update(['quote_id' => $quote->id, 'processed_at' => now(), 'error' => null]);

            return $quote;
        } catch (Throwable $e) {
            $draft->update(['processed_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 1000)]);
            report($e);

            return null;
        }
    }

    private function create(array $p, ShopifyDraftOrder $draft): Quote
    {
        $quote = Quote::create([
            'number' => $this->numbers->next(),
            'kind' => $draft->anonymous ? 'wishlist' : 'quote',
            'status' => $draft->anonymous ? 'SAVELIST' : 'NEW',
            'source' => 'shopify',
            'shopify_draft_order_id' => $draft->id,
            'valid_until' => now()->addDays((int) config('quotes.valid_days'))->toDateString(),
            'lead_time' => config('quotes.default_lead_time'),
        ] + $this->headerFields($p));

        $this->replaceItems($quote, $p['lines']);
        $this->calculator->recalculate($quote);
        AuditLog::record('quote.created_from_shopify', $quote, ['draft' => $p['name']]);

        return $quote;
    }

    private function refresh(Quote $quote, array $p): Quote
    {
        if ($quote->edited_at !== null) {
            $quote->update([
                'internal_notes' => trim(($quote->internal_notes ?? '')."\n".now()->format('j M Y H:i').': the client changed draft order '.$p['name'].' in Shopify after staff edits; items were not refreshed.'),
            ]);

            return $quote;
        }

        $quote->update($this->headerFields($p));
        $this->replaceItems($quote, $p['lines']);
        $this->calculator->recalculate($quote);

        return $quote;
    }

    private function headerFields(array $p): array
    {
        return [
            'shopify_draft_name' => $p['name'],
            'shopify_invoice_url' => $p['invoice_url'],
            'client_email' => $p['email'],
            'client_name' => $p['contact_name'],
            'client_company' => $p['company'],
            'client_phone' => $p['phone'],
            'delivery_name' => $p['address']['name'],
            'delivery_address1' => $p['address']['address1'],
            'delivery_address2' => $p['address']['address2'],
            'delivery_city' => $p['address']['city'],
            'delivery_state' => $p['address']['state'],
            'delivery_postcode' => $p['address']['postcode'],
            'delivery_country' => mb_substr($p['address']['country'] ?? 'AU', 0, 2),
            'urgency' => $p['urgency'],
            'client_notes' => $p['notes'],
        ];
    }

    private function replaceItems(Quote $quote, array $lines): void
    {
        $quote->items()->each(fn (QuoteItem $i) => $i->delete());

        foreach ($lines as $pos => $line) {
            $quote->items()->create($this->itemFromLine($line, $pos + 1));
        }
    }

    /** One draft order line -> one quote item (combined stage). */
    public function itemFromLine(array $l, int $position): array
    {
        $product = $l['product_id'] ? Product::find($l['product_id']) : null;
        $supplierId = $product?->supplier_id ?? $l['supplier_id'];
        $supplier = $supplierId ? Supplier::find($supplierId) : null;

        $item = new QuoteItem([
            'position' => $position,
            'stage' => 'combined',
            'product_id' => $product?->id ?? $l['product_id'],
            'supplier_id' => $supplierId,
            'supplier_name' => $supplier?->name,
            'product_code' => $product?->code ?? $l['product_code'],
            'name' => $l['name'] ?: ($product?->name ?? 'Product'),
            'colour' => $l['colour'],
            'image_url' => $this->imageFor($product, $l['colour']),
            'description' => $product?->detail?->description,
            'quantity' => $l['quantity'],
            'shopify_variant_id' => $l['shopify_variant_id'],
            'shopify_sku' => $l['sku'],
            'received_unit_price' => $l['price'],
        ]);

        if ($l['has_split']) {
            $item->product_unit_price = $l['undec_unit'];
            $item->product_setup_price = $l['undec_setup'];
            $item->decoration_unit_price = $l['dec_unit'];
            $item->decoration_setup_price = $l['dec_setup'];
            $item->decoration_name = $l['dec_desc'];
            $item->decoration_option_id = $l['dec_option_id'];
            $item->decoration_product_id = $l['dec_product_id'];
        } else {
            $item->product_unit_price = $l['price'];
        }

        $this->pricer->attachDefaults($item);
        $this->pricer->fillCosts($item);

        return $item->getAttributes();
    }

    private function imageFor(?Product $product, ?string $colour): ?string
    {
        if ($product === null) {
            return null;
        }
        if ($colour !== null) {
            $match = ProductColour::query()
                ->where('product_id', $product->id)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($colour)])
                ->whereNotNull('image_url')
                ->value('image_url');
            if ($match) {
                return $match;
            }
        }

        return $product->hero_image_url;
    }
}
