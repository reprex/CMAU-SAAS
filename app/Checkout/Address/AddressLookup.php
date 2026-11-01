<?php

namespace App\Checkout\Address;

/** Address autocomplete for checkout. The provider (Australia Post, Addressify, Google...) is chosen later. */
interface AddressLookup
{
    /** @return list<array{unit: ?string, street: string, suburb: string, state: string, postcode: string, country: string}> */
    public function search(string $query): array;
}
