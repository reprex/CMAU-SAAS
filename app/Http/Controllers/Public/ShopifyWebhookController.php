<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Quotes\DraftOrderIntake;
use Illuminate\Http\Request;

/** draft_orders/create and draft_orders/update webhooks, HMAC-verified. */
class ShopifyWebhookController extends Controller
{
    public function draftOrders(Request $request, DraftOrderIntake $intake)
    {
        $secret = (string) config('shopify.webhook_secret');
        $given = (string) $request->header('X-Shopify-Hmac-Sha256');
        $expected = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));

        if ($secret === '' || $given === '' || ! hash_equals($expected, $given)) {
            return response('Invalid signature', 401);
        }
        if (mb_strtolower((string) $request->header('X-Shopify-Shop-Domain')) !== mb_strtolower((string) config('shopify.store'))) {
            return response('Unknown shop', 401);
        }

        $payload = json_decode($request->getContent(), true);
        if (! is_array($payload) || ! isset($payload['id'])) {
            return response('Bad payload', 400);
        }

        $intake->receive($payload);

        return response('OK', 200);
    }
}
