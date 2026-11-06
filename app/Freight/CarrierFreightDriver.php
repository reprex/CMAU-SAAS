<?php

namespace App\Freight;

/**
 * Live carrier rates where the keys are set: MachShip (TNT) in Australia, FedEx overseas; the placeholder
 * estimate otherwise (docs/freight.md).
 */
class CarrierFreightDriver implements FreightDriver
{
    public function __construct(
        private readonly MachshipFreightDriver $machship,
        private readonly FedexFreightDriver $fedex,
        private readonly EstimateFreightDriver $estimate,
    ) {}

    public function quote(Consignment $c, string $country, ?string $postcode): array
    {
        $country = mb_strtoupper($country);
        $driver = $country === 'AU' ? ($this->machship->configured() ? $this->machship : null) : ($this->fedex->configured() ? $this->fedex : null);

        return ($driver ?? $this->estimate)->quote($c, $country, $postcode);
    }
}
