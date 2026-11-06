<?php

namespace App\Console\Commands;

use App\Freight\Consignment;
use App\Freight\FreightDriver;
use App\Freight\PackData;
use Illuminate\Console\Command;

/** Ask the live carriers for a price (docs/freight.md): `php artisan freight:test AU 3066` or `freight:test NZ 1010`. */
class FreightTestCommand extends Command
{
    protected $signature = 'freight:test {country=AU} {postcode=3066} {--units=100 : T-shirts in the consignment}';

    protected $description = 'Price a sample consignment of tees with the configured freight driver (MachShip / FedEx)';

    public function handle(FreightDriver $driver, PackData $packs): int
    {
        $c = Consignment::from([['pack' => $packs->defaults('t-shirt'), 'units' => (int) $this->option('units')]])->withValue(1500);
        $r = $driver->quote($c, (string) $this->argument('country'), (string) $this->argument('postcode'));
        $this->line(sprintf('%s · %s %s · %d cartons, %.1f kg · source %s', $r['ok'] ? 'OK' : 'NOT PRICED', $r['country'], $r['zone'] ?? '', $r['cartons'], $r['kg'], $r['source'] ?? '?'));
        if (! $r['ok']) {
            $this->error((string) $r['message']);
            $this->line('Details are in storage/logs/laravel.log (machship / fedex).');

            return self::FAILURE;
        }
        $this->table(['Service', 'Price ex GST', 'Cost', 'Business days'], array_map(fn ($o) => [$o['name'], number_format($o['price'], 2), isset($o['cost']) ? number_format((float) $o['cost'], 2) : '-', $o['days'][0].'-'.$o['days'][1]], $r['options']));

        return self::SUCCESS;
    }
}
