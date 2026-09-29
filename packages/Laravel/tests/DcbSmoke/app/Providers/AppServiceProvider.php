<?php

namespace App\DcbSmoke\Laravel\Providers;

use App\DcbSmoke\Laravel\Application\CouponService;
use App\DcbSmoke\Laravel\Application\EventsConverter;
use App\DcbSmoke\Laravel\Configuration\EcotoneConfiguration;
use Illuminate\Support\ServiceProvider;

/**
 * licence Enterprise
 */
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CouponService::class, fn () => new CouponService());
        $this->app->singleton(EventsConverter::class, fn () => new EventsConverter());
        $this->app->singleton(EcotoneConfiguration::class, fn () => new EcotoneConfiguration());
    }

    public function boot(): void
    {
    }
}
