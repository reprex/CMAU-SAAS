<?php

namespace App\Http\Controllers\Public;

use App\Checkout\Address\AddressLookup;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Public\Concerns\GuardsWebsiteOrders;
use Illuminate\Http\Request;

/** GET crowdmerch/address?q= : checkout address suggestions (driver "none" returns none). */
class AddressLookupController extends Controller
{
    use GuardsWebsiteOrders;

    public function __invoke(Request $request, AddressLookup $lookup)
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->preflight($request);
        }
        if ($blocked = $this->guard($request, 'address', (int) config('website.address.per_hour'))) {
            return $blocked;
        }
        $q = trim((string) $request->query('q', ''));

        return $this->reply($request, ['success' => true, 'results' => mb_strlen($q) < 3 ? [] : array_slice($lookup->search(mb_substr($q, 0, 120)), 0, 8)]);
    }
}
