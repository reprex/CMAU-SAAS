<?php

namespace App\Mail\Concerns;

use App\Models\Quote;
use App\Models\WebsiteOrder;

/**
 * Quote and invoice emails with embroidery carry the stitch-count note (Scott, 7 Nov 2026): the price is
 * for a 5,000 stitch (small) design, to be confirmed before payment.
 */
class EmbroideryNote
{
    public const TEXT = 'The quoted price is based on a 5000 stitch count (small) embroidery. It is subject to us confirming the actual stitch count and thread colours in your design. Price variations are usually small or nil, but please hold off making payment until we confirm the price.';

    public static function forQuote(Quote $quote): ?string
    {
        $texts = $quote->items()->get(['description', 'decoration_name'])->flatMap(fn ($i) => [$i->description, $i->decoration_name])
            ->merge($quote->extras()->pluck('description'))
            ->push(json_encode($quote->cart_state['positions'] ?? []));

        return self::mentions($texts->all()) ? self::TEXT : null;
    }

    public static function forOrder(WebsiteOrder $order): ?string
    {
        $order->loadMissing('positions');
        $texts = $order->positions->flatMap(fn ($p) => [$p->method, $p->decoration_name])->all();

        return self::mentions($texts) ? self::TEXT : null;
    }

    private static function mentions(array $texts): bool
    {
        foreach ($texts as $t) {
            if (is_string($t) && preg_match('/embroider/i', $t)) {
                return true;
            }
        }

        return false;
    }
}
