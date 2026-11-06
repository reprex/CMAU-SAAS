<?php

namespace App\Providers;

use App\Checkout\Address\AddressLookup;
use App\Checkout\Address\NoAddressLookup;
use App\Checkout\Sms\LogSmsSender;
use App\Checkout\Sms\SmsSender;
use App\Freight\CarrierFreightDriver;
use App\Freight\EstimateFreightDriver;
use App\Freight\FreightDriver;
use App\Mail\Transport\GraphTransport;
use App\Sources\Promodata\PromodataClient;
use App\Sources\Promodata\PromodataSource;
use App\Sources\SupplierSource;
use App\Support\PhpVersionGuard;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PromodataClient::class, fn () => new PromodataClient(
            baseUrl: config('promodata.base_url'),
            token: (string) config('promodata.token'),
            timeout: (int) config('promodata.timeout'),
        ));

        // The catalogue sync talks to a SupplierSource, so Promodata can later be
        // swapped for (or combined with) direct supplier APIs.
        $this->app->bind(SupplierSource::class, PromodataSource::class);

        // Website checkout drivers (docs/website-checkout.md). Each is chosen in config
        // so MachShip/FedEx, an SMS provider and an address provider can be added later.
        $this->app->bind(FreightDriver::class, fn ($app) => match (config('freight.driver')) {
            'carriers' => $app->make(CarrierFreightDriver::class),
            default => $app->make(EstimateFreightDriver::class),
        });
        $this->app->bind(SmsSender::class, fn ($app) => match (config('website.sms.driver')) {
            default => $app->make(LogSmsSender::class),
        });
        $this->app->bind(AddressLookup::class, fn ($app) => match (config('website.address.driver')) {
            default => $app->make(NoAddressLookup::class),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Mail::extend('graph', fn (array $config) => new GraphTransport(
            (string) ($config['tenant'] ?? ''), (string) ($config['client_id'] ?? ''), (string) ($config['client_secret'] ?? ''), (string) ($config['mailbox'] ?? ''),
        ));
        if ($this->app->environment('production')) {
            PhpVersionGuard::check(config('catalogue.minimum_php_version'));
        }
    }
}
