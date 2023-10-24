<?php

namespace Monorepo\ExampleAppEventSourcing\Laravel\app\Providers;

use Illuminate\Support\ServiceProvider;
use Monorepo\ExampleAppEventSourcing\Common\PriceChangeOverTimeProjection;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PriceChangeOverTimeProjection::class, PriceChangeOverTimeProjection::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
    }
}
