<?php

namespace App\Http\Controllers\Public;

use App\Checkout\AccountTokens;
use App\Checkout\CheckoutPricer;
use App\Checkout\OrderCreator;
use App\Checkout\SamplesPricer;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Public\Concerns\GuardsWebsiteOrders;
use App\Models\AuditLog;
use App\Staff\StaffPasses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * CMAUDEV-CHECKOUT (docs/website-checkout.md):
 *  - POST crowdmerch/checkout/price  JSON: price the cart, live stock, freight,
 *    delivery estimate and whether it can be ordered directly (with the issues if not);
 *  - POST crowdmerch/checkout/order  multipart (a logo file per position):
 *    recompute everything, create the order CM-xxxxx, and return its number and
 *    a My Orders account token;
 *  - POST crowdmerch/checkout/samples/price  JSON: the samples cart by factory, freight, GST and ETA;
 *  - POST crowdmerch/checkout/samples/order  JSON or form: one samples order and one invoice.
 * Prices are always worked out here; any price the client sends is ignored.
 * Replies carry our codes only, never the supplier's name or its own codes.
 */
class WebsiteCheckoutController extends Controller
{
    use GuardsWebsiteOrders;

    public function price(Request $request, CheckoutPricer $pricer)
    {
        if ($blocked = $this->guard($request, 'checkout-price', (int) config('website.orders.price_per_hour'))) {
            return $blocked;
        }
        $v = Validator::make($request->all(), $this->cartRules() + [
            'delivery' => 'required|array',
            'delivery.country' => ['required', 'string', Rule::in($this->countries())],
            'delivery.postcode' => 'nullable|string|max:16',
            'need_by' => 'nullable|date_format:Y-m-d',
            'positions.*.has_logo' => 'nullable|boolean',
        ]);
        if ($v->fails()) {
            return $this->reply($request, ['success' => false, 'message' => 'Please check the order details.', 'errors' => $v->errors()], 422);
        }
        $data = $v->validated();

        try {
            $result = $pricer->evaluate($this->cart($data, fn ($p) => (bool) ($p['has_logo'] ?? false)) + ['delivery' => $data['delivery']]);
        } catch (Throwable $e) {
            report($e);

            return $this->reply($request, ['success' => false, 'message' => 'Sorry, we could not price this just now. Please try again.'], 503);
        }
        if (! $result['found']) {
            return $this->reply($request, ['success' => false, 'message' => 'That product cannot be ordered online.'], 404);
        }
        unset($result['found']);

        return $this->reply($request, ['success' => true] + CheckoutPricer::publicReply($result));
    }

    public function order(Request $request, OrderCreator $creator, AccountTokens $tokens)
    {
        if ($blocked = $this->guard($request, 'checkout-order', (int) config('website.orders.orders_per_hour'), write: true)) {
            return $blocked;
        }
        $mimes = implode(',', config('website.orders.logo_mimes'));
        $v = Validator::make($request->all(), $this->cartRules() + [
            'positions.*.logo' => "required|file|mimes:{$mimes}|max:".(int) config('website.orders.logo_max_kb'),
            'contact' => 'required|array',
            'contact.name' => 'required|string|max:120',
            'contact.organisation' => 'nullable|string|max:160',
            'contact.email' => 'required|email:rfc|max:190',
            'contact.phone' => ['required', 'string', 'max:40', 'regex:/^[0-9+()\s.-]{6,40}$/'],
            'address' => 'required|array',
            'address.unit' => 'nullable|string|max:60',
            'address.street' => 'required|string|max:190',
            'address.suburb' => 'required|string|max:120',
            'address.state' => ['nullable', 'string', 'max:60', 'required_if:address.country,AU', Rule::when($request->input('address.country') === 'AU', [Rule::in(['VIC', 'NSW', 'QLD', 'SA', 'WA', 'TAS', 'NT', 'ACT'])])],
            'address.postcode' => ['required', 'string', 'max:16', Rule::when($request->input('address.country') === 'AU', ['regex:/^\d{4}$/'])],
            'address.country' => ['required', 'string', Rule::in($this->countries())],
            'need_by' => 'required|date_format:Y-m-d|after:today',
            'event' => 'nullable|array',
            'event.name' => 'nullable|string|max:160',
            'event.date' => 'nullable|date_format:Y-m-d',
            'event.place' => 'nullable|string|max:160',
            'freight_service' => 'required|string|max:32',
            'proof_ack' => 'accepted',
        ], [
            'proof_ack.accepted' => 'Please tick to confirm: '.config('website.orders.proof_ack_text'),
            'positions.*.logo.required' => 'Upload your logo for each branding position.',
        ]);
        if ($v->fails()) {
            return $this->reply($request, ['success' => false, 'message' => 'Please check the order details.', 'errors' => $v->errors()], 422);
        }
        $raw = $v->validated();
        $logos = [];
        foreach (array_keys($raw['positions']) as $n => $i) {
            if ($file = $request->file("positions.{$i}.logo")) {
                $logos[$n] = $file; // in the same order as the positions below
            }
        }
        $data = $this->cart($raw, fn () => true) + $raw;

        try {
            ['order' => $order, 'result' => $result] = $creator->create($data, $logos, $request->ip());
            // Placed by staff for a client (cart page staff panel): who placed it, for the record.
            if ($order && ($pass = app(StaffPasses::class)->resolve($request, 'assist'))) {
                $order->forceFill(['placed_by' => $pass->user_id])->save();
                AuditLog::record('website_order.placed_by_staff', $order, ['user' => $pass->user->name]);
            }
        } catch (Throwable $e) {
            report($e);

            return $this->reply($request, ['success' => false, 'message' => 'Sorry, we could not create your order just now. Please try again or call '.config('quotes.pdf.phone').'.'], 503);
        }
        if ($order === null) {
            if (! ($result['found'] ?? false)) {
                return $this->reply($request, ['success' => false, 'message' => 'That product cannot be ordered online.'], 404);
            }

            return $this->reply($request, [
                'success' => false,
                'message' => 'This order needs our team to quote it.',
                'can_order' => false,
                'issues' => $result['issues'],
                'totals' => $result['totals'],
            ], 422);
        }

        $token = $tokens->issue($order->email);

        return $this->reply($request, [
            'success' => true,
            'order_number' => $order->number,
            'email' => $order->email,
            'total' => (float) $order->total,
            'inc_gst' => ! $order->export,
            'export' => $order->export,
            'duty_note' => $order->export ? config('website.orders.duty_note') : null,
            'eta' => $order->eta->toDateString(),
            'alerts' => $order->alerts ?? [],
            'invoice' => config('website.orders.auto_send_invoice') ? 'sending' : 'pending_review',
            'account_token' => $token,
            'account_token_expires_at' => date(DATE_ATOM, (int) $tokens->expiresAt($token)),
        ], 201);
    }

    public function samplesPrice(Request $request, SamplesPricer $pricer)
    {
        if ($blocked = $this->guard($request, 'samples-price', (int) config('website.orders.price_per_hour'))) {
            return $blocked;
        }
        $v = Validator::make($request->all(), $this->sampleRules() + [
            'postcode' => 'nullable|string|max:16',
            'country' => 'nullable|string|size:2',
        ]);
        if ($v->fails()) {
            return $this->reply($request, ['success' => false, 'message' => 'Please check your samples.', 'errors' => $v->errors()], 422);
        }
        $data = $v->validated();
        try {
            $result = $pricer->evaluate(['items' => $this->sampleItems($data), 'postcode' => $data['postcode'] ?? null, 'country' => $data['country'] ?? 'AU']);
        } catch (Throwable $e) {
            report($e);

            return $this->reply($request, ['success' => false, 'message' => 'Sorry, we could not price your samples just now. Please try again.'], 503);
        }

        return $this->reply($request, ['success' => true] + SamplesPricer::publicReply($result));
    }

    public function samplesOrder(Request $request, OrderCreator $creator, AccountTokens $tokens)
    {
        if ($blocked = $this->guard($request, 'samples-order', (int) config('website.orders.orders_per_hour'), write: true)) {
            return $blocked;
        }
        $v = Validator::make($request->all(), $this->sampleRules() + $this->contactRules($request));
        if ($v->fails()) {
            return $this->reply($request, ['success' => false, 'message' => 'Please check the order details.', 'errors' => $v->errors()], 422);
        }
        $data = $v->validated();
        $data['items'] = $this->sampleItems($data);

        try {
            ['order' => $order, 'result' => $result] = $creator->createSamples($data, $request->ip());
        } catch (Throwable $e) {
            report($e);

            return $this->reply($request, ['success' => false, 'message' => 'Sorry, we could not create your sample order just now. Please try again or call '.config('quotes.pdf.phone').'.'], 503);
        }
        if ($order === null) {
            return $this->reply($request, [
                'success' => false,
                'message' => 'These samples can\'t be ordered online. Please check the issues or ask us for a quote.',
                'can_order' => false,
                'issues' => $result['issues'],
                'totals' => $result['totals'],
            ], 422);
        }
        $token = $tokens->issue($order->email);

        return $this->reply($request, [
            'success' => true,
            'kind' => 'samples',
            'order_number' => $order->number,
            'email' => $order->email,
            'total' => (float) $order->total,
            'inc_gst' => true,
            'eta' => $order->eta->toDateString(),
            'invoice' => config('website.orders.auto_send_invoice') ? 'sending' : 'pending_review',
            'account_token' => $token,
            'account_token_expires_at' => date(DATE_ATOM, (int) $tokens->expiresAt($token)),
        ], 201);
    }

    // ---------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function sampleRules(): array
    {
        return [
            'items' => 'present|array|max:'.(int) config('website.samples.max_items'),
            'items.*.product' => 'required|integer|min:1',
            'items.*.colour' => 'nullable|string|max:120',
        ];
    }

    /** @return list<array{product: int, colour: ?string}> */
    private function sampleItems(array $data): array
    {
        return collect($data['items'] ?? [])->map(fn ($i) => ['product' => (int) $i['product'], 'colour' => isset($i['colour']) ? (string) $i['colour'] : null])->values()->all();
    }

    /** Contact and delivery address, as for an order (no need-by, branding or proof for samples). @return array<string, mixed> */
    private function contactRules(Request $request): array
    {
        return [
            'contact' => 'required|array',
            'contact.name' => 'required|string|max:120',
            'contact.organisation' => 'nullable|string|max:160',
            'contact.email' => 'required|email:rfc|max:190',
            'contact.phone' => ['required', 'string', 'max:40', 'regex:/^[0-9+()\s.-]{6,40}$/'],
            'address' => 'required|array',
            'address.unit' => 'nullable|string|max:60',
            'address.street' => 'required|string|max:190',
            'address.suburb' => 'required|string|max:120',
            'address.state' => ['nullable', 'string', 'max:60', 'required_if:address.country,AU', Rule::when($request->input('address.country') === 'AU', [Rule::in(['VIC', 'NSW', 'QLD', 'SA', 'WA', 'TAS', 'NT', 'ACT'])])],
            'address.postcode' => ['required', 'string', 'max:16', Rule::when($request->input('address.country') === 'AU', ['regex:/^\d{4}$/'])],
            'address.country' => ['required', 'string', 'size:2'],
        ];
    }

    /** @return array<string, mixed> */
    private function cartRules(): array
    {
        return [
            'product_id' => 'required|integer|min:1',
            'lines' => 'required|array|min:1|max:'.(int) config('website.orders.max_lines'),
            'lines.*.product_id' => 'required|integer|min:1',
            'lines.*.colour' => 'required|string|max:120',
            'lines.*.size' => 'required|string|max:60',
            'lines.*.qty' => 'required|integer|min:0|max:'.(int) config('website.orders.max_units'),
            'positions' => 'required|array|min:1|max:'.(int) config('website.orders.max_positions'),
            'positions.*.name' => 'required|string|max:60',
            'positions.*.decID' => 'required|string|max:190',
            'positions.*.colours' => 'nullable|integer|min:1|max:16',
            'positions.*.width_mm' => 'nullable|integer|min:5|max:1000',
            'positions.*.preflight' => 'nullable|string|in:ready,help',
            'positions.*.preflight_notes' => 'nullable|array|max:8',
            'positions.*.preflight_notes.*' => 'string|max:200',
            'freight_service' => 'nullable|string|max:32',
            'options' => 'nullable|array|max:20',
            'options.*.id' => 'required|string|max:190',
            'options.*.n' => 'nullable|integer|min:1|max:20',
        ];
    }

    /** The cart as CheckoutPricer reads it: only the fields it needs (no client prices). */
    private function cart(array $data, callable $hasLogo): array
    {
        return [
            'product_id' => (int) $data['product_id'],
            'lines' => collect($data['lines'])->filter(fn ($l) => (int) $l['qty'] > 0)->map(fn ($l) => [
                'product_id' => (int) $l['product_id'], 'colour' => (string) $l['colour'], 'size' => (string) $l['size'], 'qty' => (int) $l['qty'],
            ])->values()->all(),
            'positions' => collect($data['positions'])->map(fn ($p) => [
                'name' => (string) $p['name'], 'decID' => (string) $p['decID'],
                'colours' => isset($p['colours']) ? (int) $p['colours'] : null, 'width_mm' => isset($p['width_mm']) ? (int) $p['width_mm'] : null,
                'has_logo' => $hasLogo($p),
            ])->values()->all(),
            'options' => collect($data['options'] ?? [])->map(fn ($o) => ['id' => (string) $o['id'], 'n' => (int) ($o['n'] ?? 1)])->values()->all(),
            'freight_service' => $data['freight_service'] ?? null,
            'need_by' => $data['need_by'] ?? null,
        ];
    }

    /** @return list<string> */
    private function countries(): array
    {
        return array_merge(['AU'], array_keys(config('freight.intl_countries')));
    }
}
