<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Jobs\SendInstantQuote;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Quotes\DraftOrderIntake;
use App\Quotes\ItemPricer;
use App\Quotes\QuoteCalculator;
use App\Quotes\QuoteNumbers;
use App\Quotes\WebsiteLinePricer;
use App\Shopify\ShopifyAdmin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * Website quote API: the CMAU theme's Quick Quote and wish-list calls, taking
 * over from app.crowdmerch.com.au/crowdmerch/homepage/*.
 *
 *  - createQuoteDraftOrder: prices the line(s), creates the Shopify draft order
 *    (development store only unless SHOPIFY_ALLOW_LIVE), creates the quote and
 *    queues the instant quote email.
 *  - addToWishlist / getDraftOrderItems / removeDiscontinuedItem: anonymous wish
 *    lists are kept here (quote kind "wishlist", token ActiveWL), not as Shopify
 *    draft orders, so automated traffic no longer fills Shopify.
 *
 * Reply shapes are provisional until the current app's replies are captured
 * from the live product page; field names follow the theme's requests.
 */
class WebsiteQuoteController extends Controller
{
    public function __construct(
        private readonly WebsiteLinePricer $linePricer,
        private readonly ShopifyAdmin $shopify,
    ) {}

    public function createQuoteDraftOrder(Request $request, DraftOrderIntake $intake)
    {
        if ($blocked = $this->guard($request, 'quote', (int) config('shopify.website_api.quotes_per_hour'))) {
            return $blocked;
        }

        $data = $request->validate([
            'email' => 'required|email:rfc|max:190',
            'variantId' => 'required|integer|min:1',
            'quantity' => 'required|integer|min:1|max:1000000',
            'decID' => 'nullable|string|max:190',
            'ActiveWL' => 'nullable|string|max:64',
            'urgency' => 'nullable|string|max:190',
            'comments' => 'nullable|string|max:5000',
        ]);

        try {
            $lines = [];
            $line = $this->shopifyLine((int) $data['variantId'], (int) $data['quantity'], $data['decID'] ?? null);
            if ($line === null) {
                return $this->reply($request, ['success' => false, 'message' => 'That product or decoration cannot be quoted online. Please contact us.'], 422);
            }
            $lines[] = $line;

            // A Quick Quote from the wish list page quotes the whole list.
            $wishlist = $this->wishlist($data['ActiveWL'] ?? null);
            foreach ($wishlist?->items ?? [] as $item) {
                if ($item->shopify_variant_id && $item->shopify_variant_id !== (int) $data['variantId']) {
                    $extra = $this->shopifyLine($item->shopify_variant_id, $item->quantity, $item->decoration_option_id);
                    if ($extra) {
                        $lines[] = $extra;
                    }
                }
            }

            $draftId = $this->shopify->createDraftOrderGraphql([
                'email' => mb_strtolower($data['email']),
                'note' => 'Urgency: '.($data['urgency'] ?? '').' Notes: '.($data['comments'] ?? ''),
                'tags' => ['saas-quote'],
                'lineItems' => $lines,
            ]);

            $draft = $intake->receive($this->shopify->draftOrder($draftId));
            $quote = Quote::find($draft->quote_id);
            if ($quote) {
                $quote->update(['source' => 'website']);
                if ($wishlist) {
                    $wishlist->update(['status' => 'QUOTE', 'internal_notes' => 'Quoted as '.$quote->number]);
                }
                if (config('shopify.website_api.email_instant_quote')) {
                    SendInstantQuote::dispatch($quote->id);
                }
            }

            return $this->reply($request, ['success' => true, 'draftOrderId' => $draftId, 'name' => $draft->name, 'quoteNumber' => $quote?->number]);
        } catch (Throwable $e) {
            report($e);

            return $this->reply($request, ['success' => false, 'message' => 'Sorry, we could not create your quote just now. Please try again or call 1300 727 685.'], 503);
        }
    }

    public function addToWishlist(Request $request, QuoteNumbers $numbers, ItemPricer $pricer, QuoteCalculator $calculator)
    {
        if ($blocked = $this->guard($request, 'wishlist', (int) config('shopify.website_api.wishlist_adds_per_hour'))) {
            return $blocked;
        }

        $data = $request->validate([
            'variantId' => 'required|integer|min:1',
            'quantity' => 'required|integer|min:1|max:1000000',
            'decID' => 'nullable|string|max:190',
            'ActiveWL' => 'nullable|string|max:64',
        ]);

        $variant = $this->variant((int) $data['variantId']);
        $priced = $variant ? $this->linePricer->price($variant['product_id'], (int) $data['quantity'], $data['decID'] ?? null) : null;
        if ($priced === null) {
            return $this->reply($request, ['success' => false, 'message' => 'That product cannot be added online.'], 422);
        }

        $wishlist = $this->wishlist($data['ActiveWL'] ?? null) ?? Quote::create([
            'number' => $numbers->next(),
            'kind' => 'wishlist',
            'status' => 'SAVELIST',
            'source' => 'website',
            'public_token' => Str::random(40),
            'valid_until' => now()->addDays((int) config('quotes.valid_days'))->toDateString(),
        ]);

        $product = Product::with('supplier')->find($variant['product_id']);
        $p = $priced['properties'];
        $item = new QuoteItem([
            'quote_id' => $wishlist->id,
            'position' => $wishlist->nextPosition(),
            'product_id' => $variant['product_id'],
            'supplier_id' => $product?->supplier_id,
            'supplier_name' => $product?->supplier?->name,
            'product_code' => $product?->code,
            'name' => $product?->name ?? $variant['title'],
            'colour' => $variant['colour'],
            'image_url' => $product?->hero_image_url,
            'quantity' => (int) $data['quantity'],
            'shopify_variant_id' => (int) $data['variantId'],
            'shopify_sku' => $variant['sku'],
            'received_unit_price' => $priced['price'],
            'product_unit_price' => (float) $p['undecunit'],
            'product_setup_price' => (float) $p['undecsetup'],
            'decoration_unit_price' => (float) $p['decunit'],
            'decoration_setup_price' => (float) $p['decsetup'],
            'decoration_name' => $p['decdesc'] ?? null,
            'decoration_option_id' => $p['decID'] ?? null,
        ]);
        $pricer->attachDefaults($item);
        $pricer->fillCosts($item);
        $item->save();
        $calculator->recalculate($wishlist);

        return $this->reply($request, ['success' => true, 'ActiveWL' => $wishlist->public_token, 'count' => $wishlist->items()->count()]);
    }

    public function getDraftOrderItems(Request $request)
    {
        if ($blocked = $this->guard($request, 'read', 120)) {
            return $blocked;
        }
        $wishlist = $this->wishlist((string) $request->input('ActiveWL'));

        return $this->reply($request, [
            'success' => $wishlist !== null,
            'items' => $wishlist ? $wishlist->items->map(fn (QuoteItem $i) => [
                'id' => $i->id,
                'title' => $i->name,
                'variantId' => $i->shopify_variant_id,
                'sku' => $i->shopify_sku,
                'colour' => $i->colour,
                'quantity' => $i->quantity,
                'price' => number_format((float) $i->received_unit_price, 2, '.', ''),
                'decName' => $i->decoration_name,
                'decID' => $i->decoration_option_id,
                'image' => $i->image_url,
                'discontinued' => (bool) $i->product?->discontinued,
            ])->values() : [],
        ]);
    }

    public function removeDiscontinuedItem(Request $request)
    {
        if ($blocked = $this->guard($request, 'wishlist', (int) config('shopify.website_api.wishlist_adds_per_hour'))) {
            return $blocked;
        }
        $wishlist = $this->wishlist((string) $request->input('ActiveWL'));
        $id = (int) $request->input('itemId', $request->input('lineId'));
        $removed = $wishlist ? $wishlist->items()->whereKey($id)->delete() : 0;

        return $this->reply($request, ['success' => $removed > 0, 'count' => $wishlist?->items()->count() ?? 0]);
    }

    public function preflight(Request $request)
    {
        return $this->reply($request, [], 204);
    }

    // ---------------------------------------------------------------------

    /** Origin allow-list, honeypot and per-IP rate limit. */
    private function guard(Request $request, string $bucket, int $perHour): ?JsonResponse
    {
        if (! config('shopify.website_api.enabled')) {
            return response()->json(['success' => false, 'message' => 'Not available'], 404);
        }
        $origin = (string) $request->headers->get('Origin', '');
        if (! in_array($origin, config('shopify.website_api.allowed_origins'), true)) {
            return response()->json(['success' => false, 'message' => 'Origin not allowed'], 403);
        }
        if (trim((string) $request->input('website', '')) !== '') {
            return $this->reply($request, ['success' => true]); // honeypot: look normal, do nothing
        }
        $key = "website-{$bucket}:".hash('sha256', (string) $request->ip());
        if (RateLimiter::tooManyAttempts($key, $perHour)) {
            return $this->reply($request, ['success' => false, 'message' => 'Too many requests. Please try again later.'], 429);
        }
        RateLimiter::hit($key, 3600);

        return null;
    }

    private function reply(Request $request, array $body, int $status = 200): JsonResponse
    {
        $origin = (string) $request->headers->get('Origin', '');
        $headers = ['Vary' => 'Origin'];
        if (in_array($origin, config('shopify.website_api.allowed_origins'), true)) {
            $headers += [
                'Access-Control-Allow-Origin' => $origin,
                'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type, X-Requested-With',
                'Access-Control-Max-Age' => '86400',
            ];
        }

        return response()->json($body, $status, $headers);
    }

    private function wishlist(?string $token): ?Quote
    {
        if ($token === null || ! preg_match('/^[A-Za-z0-9]{40}$/', $token)) {
            return null;
        }

        return Quote::query()->where('public_token', $token)->where('kind', 'wishlist')->with('items.product')->first();
    }

    /** @return array{product_id: int, sku: string|null, title: string, colour: string|null}|null */
    private function variant(int $variantId): ?array
    {
        return cache()->remember("variant:{$variantId}", 3600, function () use ($variantId) {
            $v = $this->shopify->variant($variantId);
            if (! $v || ! preg_match('/^(\d+)/', (string) ($v['sku'] ?? ''), $m)) {
                return null;
            }

            return ['product_id' => (int) $m[1], 'sku' => $v['sku'], 'title' => (string) ($v['title'] ?? ''), 'colour' => $v['option1'] ?? $v['title'] ?? null];
        });
    }

    /** @return array<string, mixed>|null DraftOrderLineItemInput */
    private function shopifyLine(int $variantId, int $qty, ?string $decId): ?array
    {
        $variant = $this->variant($variantId);
        $priced = $variant ? $this->linePricer->price($variant['product_id'], $qty, $decId) : null;
        if ($priced === null) {
            return null;
        }

        return [
            'variantId' => 'gid://shopify/ProductVariant/'.$variantId,
            'quantity' => $qty,
            'priceOverride' => ['amount' => number_format($priced['price'], 2, '.', ''), 'currencyCode' => 'AUD'],
            'customAttributes' => collect($priced['properties'])->map(fn ($v, $k) => ['key' => $k, 'value' => (string) $v])->values()->all(),
        ];
    }
}
