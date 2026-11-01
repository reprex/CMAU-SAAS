<?php

namespace App\Http\Controllers\Public;

use App\Checkout\AccountCodes;
use App\Checkout\AccountTokens;
use App\Checkout\OutboundEmails;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Public\Concerns\GuardsWebsiteOrders;
use App\Models\AuditLog;
use App\Models\WebsiteOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * My Orders (docs/website-checkout.md):
 *  - POST crowdmerch/account/code    {email, via: email|sms}  always the same generic reply;
 *  - POST crowdmerch/account/verify  {email, code}            a signed 90-day token;
 *  - GET  crowdmerch/account/orders  Bearer token             that email's orders only;
 *  - POST crowdmerch/account/change  Bearer token, {order_number, message}  until Dispatched.
 */
class WebsiteAccountController extends Controller
{
    use GuardsWebsiteOrders;

    private const GENERIC = "If that email has orders with us, we've sent a 6-digit code. It expires in 10 minutes.";

    public function __construct(private readonly AccountTokens $tokens) {}

    public function code(Request $request, AccountCodes $codes)
    {
        $generic = ['success' => true, 'message' => self::GENERIC];
        if ($blocked = $this->guard($request, 'account-code', (int) config('website.account.codes_per_ip_per_hour'), write: true, honeypotReply: $generic)) {
            return $blocked;
        }
        $v = Validator::make($request->all(), ['email' => 'required|email:rfc|max:190', 'via' => 'nullable|in:email,sms']);
        if ($v->fails()) {
            return $this->reply($request, ['success' => false, 'message' => 'Enter a valid email address.', 'errors' => $v->errors()], 422);
        }
        $codes->request($request->input('email'), $request->input('via', 'email'));

        return $this->reply($request, $generic);
    }

    public function verify(Request $request, AccountCodes $codes)
    {
        if ($blocked = $this->guard($request, 'account-verify', (int) config('website.account.verifies_per_ip_per_hour'))) {
            return $blocked;
        }
        $v = Validator::make($request->all(), ['email' => 'required|email:rfc|max:190', 'code' => 'required|string|max:12']);
        if ($v->fails() || ! $codes->verify($request->input('email'), (string) $request->input('code'))) {
            return $this->reply($request, ['success' => false, 'message' => 'That code is wrong or has expired. Ask for a new code.'], 422);
        }
        $email = mb_strtolower(trim($request->input('email')));
        $token = $this->tokens->issue($email);

        return $this->reply($request, ['success' => true, 'email' => $email, 'token' => $token, 'expires_at' => date(DATE_ATOM, (int) $this->tokens->expiresAt($token))]);
    }

    public function orders(Request $request)
    {
        if ($blocked = $this->guard($request, 'account-read', 240)) {
            return $blocked;
        }
        $email = $this->tokens->email($request->bearerToken());
        if ($email === null) {
            return $this->reply($request, ['success' => false, 'message' => 'Please sign in again.'], 401);
        }
        $orders = WebsiteOrder::query()->where('email', $email)->with(['lines', 'positions', 'changeRequests'])->latest('id')->limit(100)->get();

        return $this->reply($request, [
            'success' => true,
            'email' => $email,
            'stages' => WebsiteOrder::STAGES,
            'orders' => $orders->map(fn (WebsiteOrder $o) => [
                'number' => $o->number,
                'placed_at' => $o->created_at->toIso8601String(),
                'total' => (float) $o->total,
                'inc_gst' => ! $o->export,
                'export' => $o->export,
                'total_label' => $o->export ? 'ex GST (export)' : 'inc GST',
                'items' => $o->itemsSummary(),
                'stage' => $o->stage,
                'stage_name' => $o->stageName(),
                'delivery_date' => ($o->delivered_on ?? $o->eta)->toDateString(),
                'delivered' => $o->delivered_on !== null || $o->stage === WebsiteOrder::STAGE_DELIVERED,
                'need_by' => $o->need_by->toDateString(),
                'carrier' => $o->carrier,
                'consignment' => $o->consignment,
                'tracking_url' => $o->trackingUrl(),
                'can_request_change' => $o->acceptsChanges(),
                'change_requests' => $o->changeRequests->map(fn ($c) => ['at' => $c->created_at->toIso8601String(), 'message' => $c->message])->values(),
            ])->values(),
        ]);
    }

    public function change(Request $request, OutboundEmails $emails)
    {
        if ($blocked = $this->guard($request, 'account-change', (int) config('website.orders.changes_per_hour'), write: true)) {
            return $blocked;
        }
        $email = $this->tokens->email($request->bearerToken());
        if ($email === null) {
            return $this->reply($request, ['success' => false, 'message' => 'Please sign in again.'], 401);
        }
        $v = Validator::make($request->all(), ['order_number' => 'required|string|max:16', 'message' => 'required|string|min:3|max:3000']);
        if ($v->fails()) {
            return $this->reply($request, ['success' => false, 'message' => 'Tell us what you would like to change.', 'errors' => $v->errors()], 422);
        }
        $order = WebsiteOrder::query()->where('email', $email)->where('number', $request->input('order_number'))->first();
        if ($order === null) {
            return $this->reply($request, ['success' => false, 'message' => 'Order not found.'], 404);
        }
        if (! $order->acceptsChanges()) {
            return $this->reply($request, ['success' => false, 'message' => 'This order has been dispatched, so it can no longer be changed. Please call '.config('quotes.pdf.phone').'.'], 409);
        }
        $change = $order->changeRequests()->create(['message' => trim($request->input('message')), 'stage_at_request' => $order->stage]);
        AuditLog::record('website_order.change_requested', $order, ['change_id' => $change->id]);
        $emails->prepareChangeNotice($change);

        return $this->reply($request, ['success' => true, 'message' => "Sent to the CrowdMerch team. We'll reply by email. Changes can affect the price or delivery date."], 201);
    }
}
