<?php

namespace App\Http\Controllers\Public;

use App\Catalogue\ImageTagger;
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
use App\Search\Turnstile;
use App\Shopify\ShopifyAdmin;
use App\Staff\StaffQuotes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
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

    /**
     * The shopper's quote and mockup PDF (docs/quote-mockup-pdf.md; Scott, 7 Nov 2026): one product, priced
     * by the SaaS exactly as the checkout prices it, with the mockup pictures the shopper made, their logos
     * (kept privately with the quote), the similar products shown to them and the ones they hearted. Creates
     * the quote in the quote desk (source website, status QUOTE) and emails the PDF when instant quotes are on.
     */
    public function quotePdf(Request $request, StaffQuotes $quotes)
    {
        if ($blocked = $this->guard($request, 'quote', (int) config('shopify.website_api.quotes_per_hour'))) {
            return $blocked;
        }
        $cart = json_decode((string) $request->input('cart'), true);
        $mimes = implode(',', config('website.orders.logo_mimes'));
        $v = Validator::make(['cart' => $cart] + $request->only(['name', 'email', 'phone', 'need_by', 'organisation', 'similar', 'saved'])
            + ['logos' => $request->file('logos', []), 'mockups' => $request->file('mockups', [])], [
                'name' => 'required|string|max:120',
                'email' => 'required|email:rfc|max:190',
                'phone' => 'nullable|string|max:40',
                'organisation' => 'nullable|string|max:160',
                'need_by' => 'nullable|date',
                'cart' => 'required|array',
                'cart.product_id' => 'required|integer|min:1',
                'cart.lines' => 'required|array|min:1|max:'.(int) config('website.orders.max_lines'),
                'cart.lines.*.product_id' => 'required|integer|min:1',
                'cart.lines.*.colour' => 'nullable|string|max:120',
                'cart.lines.*.size' => 'nullable|string|max:60',
                'cart.lines.*.qty' => 'required|integer|min:0|max:'.(int) config('website.orders.max_units'),
                'cart.positions' => 'present|array|max:'.(int) config('website.orders.max_positions'),
                'cart.positions.*.name' => 'required|string|max:60',
                'cart.positions.*.decID' => 'required|string|max:190',
                'cart.positions.*.colours' => 'nullable|integer|min:1|max:16',
                'cart.positions.*.width_mm' => 'nullable|integer|min:5|max:1000',
                'cart.options' => 'nullable|array|max:20',
                'cart.options.*.id' => 'required|string|max:190',
                'cart.options.*.n' => 'nullable|integer|min:1|max:20',
                'similar' => 'nullable|array|max:8',
                'similar.*' => 'integer|min:1',
                'saved' => 'nullable|array|max:24',
                'saved.*' => 'integer|min:1',
                'logos' => 'array|max:8',
                'logos.*' => "file|mimes:{$mimes}|max:".(int) config('website.orders.logo_max_kb'),
                'mockups' => 'array|max:4',
                'mockups.*' => 'file|mimes:jpg,jpeg,png,webp|max:3072',
            ]);
        if ($v->fails()) {
            return $this->reply($request, ['success' => false, 'message' => 'Please check your details.', 'errors' => $v->errors()], 422);
        }
        $cart['lines'] = array_values(array_map(fn ($l) => [
            'product_id' => (int) $l['product_id'], 'colour' => trim((string) ($l['colour'] ?? '')) ?: ImageTagger::CUSTOM_PRINT,
            'size' => trim((string) ($l['size'] ?? '')) ?: 'One size', 'qty' => (int) $l['qty'],
        ], array_filter($cart['lines'], fn ($l) => (int) $l['qty'] > 0)));
        if ($cart['lines'] === []) {
            return $this->reply($request, ['success' => false, 'message' => 'Choose a quantity first.'], 422);
        }
        $client = ['name' => $request->input('name'), 'organisation' => $request->input('organisation'), 'email' => $request->input('email'), 'phone' => $request->input('phone')];
        try {
            $r = $quotes->build(null, $cart, $client, null, (array) $request->file('logos', []),
                $request->filled('need_by') ? 'Needed by '.$request->input('need_by') : null, 'website');
        } catch (Throwable $e) {
            if (! $e instanceof \RuntimeException) {
                report($e);
            }

            return $this->reply($request, ['success' => false, 'message' => $e instanceof \RuntimeException ? $e->getMessage() : 'Sorry, we could not make your quote just now. Please try again or call 1300 727 685.'], 422);
        }
        $quote = $r['quote'];
        $disk = Storage::disk(config('website.orders.artwork_disk'));
        $mockups = [];
        foreach ((array) $request->file('mockups', []) as $i => $file) {
            $path = 'quote-artwork/'.$quote->number.'/mockup-'.($i + 1).'-'.Str::random(8).'.'.(strtolower($file->getClientOriginalExtension() ?: 'jpg'));
            $disk->putFileAs(dirname($path), $file, basename($path));
            $mockups[] = ['path' => $path, 'view' => $i === 0 ? 'front' : 'back'];
        }
        $ids = fn ($key, $max) => array_values(array_slice(array_unique(array_filter(array_map('intval', (array) $request->input($key, [])), fn ($id) => $id !== (int) $cart['product_id'])), 0, $max));
        $quote->forceFill(['mockups' => $mockups ?: null, 'similar_products' => $ids('similar', 4) ?: null, 'saved_products' => $ids('saved', 12) ?: null])->save();
        if (config('shopify.website_api.email_instant_quote')) {
            SendInstantQuote::dispatch($quote->id);
        }

        return $this->reply($request, ['success' => true, 'quoteNumber' => $quote->number, 'emailed' => (bool) config('shopify.website_api.email_instant_quote'), 'issues' => array_column($r['issues'], 'message')]);
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
        // Optional human check on writes (quote requests, Add to my list): docs/website-search.md.
        if ($bucket !== 'read' && config('search.turnstile.protect_website_api')
            && ($why = app(Turnstile::class)->check($request->input('cf_turnstile'), $request->ip()))) {
            return $this->reply($request, ['success' => false, 'message' => 'Please confirm you are human and try again.', 'reason' => $why], 403);
        }

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
