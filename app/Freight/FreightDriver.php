<?php

namespace App\Freight;

/**
 * Prices delivery for a website order. The "estimate" driver uses the
 * placeholder rates in config/freight.php; MachShip (TNT, Australia) and
 * FedEx (overseas) drivers will implement this when API access is set up.
 */
interface FreightDriver
{
    /**
     * @return array{ok: bool, message: ?string, country: string, export: bool, zone: ?string, basis: ?string,
     *               cartons: int, kg: float, m3: float, chargeable_kg: ?float,
     *               options: list<array{id: string, name: string, carrier: string, days: array{0: int, 1: int}, price: float}>}
     */
    public function quote(Consignment $consignment, string $country, ?string $postcode): array;
}
