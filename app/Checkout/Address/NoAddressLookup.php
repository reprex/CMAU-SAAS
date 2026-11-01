<?php

namespace App\Checkout\Address;

/** Default driver: no suggestions, so the theme shows manual entry. */
class NoAddressLookup implements AddressLookup
{
    public function search(string $query): array
    {
        return [];
    }
}
