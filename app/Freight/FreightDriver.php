<?php

namespace App\Freight;

/**
 * Prices delivery for a website order or quote. "estimate" uses the placeholder
 * rates in config/freight.php; "carriers" asks MachShip (TNT, Australia) and
 * FedEx (overseas) for live rates (docs/freight.md).
 */
interface FreightDriver
{
    /**
     * estimated: the weights aren't all from Promodata (say delivery is an estimate).
     * price is ex GST, what the client pays; cost (when known) is what the carrier charges us.
     *
     * @return array{ok: bool, message: ?string, country: string, export: bool, zone: ?string, basis: ?string,
     *               cartons: int, kg: float, m3: float, chargeable_kg: ?float, estimated: bool, source: string,
     *               options: list<array{id: string, name: string, carrier: string, days: array{0: int, 1: int}, price: float, cost?: ?float}>}
     */
    public function quote(Consignment $consignment, string $country, ?string $postcode): array;
}
