<?php

namespace App\Providers;

use App\Sources\Promodata\PromodataClient;
use App\Sources\Promodata\PromodataSource;
use App\Sources\SupplierSource;
use App\Support\PhpVersionGuard;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('production')) {
            PhpVersionGuard::check(config('catalogue.minimum_php_version'));
        }
    }
}
