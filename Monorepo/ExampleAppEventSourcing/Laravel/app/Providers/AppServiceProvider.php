<?php

namespace Monorepo\ExampleAppEventSourcing\Laravel\app\Providers;

use Enqueue\Dbal\DbalConnectionFactory;
use Illuminate\Support\ServiceProvider;
use Monorepo\ExampleAppEventSourcing\ProophProjection\PriceChangeOverTimeProjection;
use Psr\Log\NullLogger;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PriceChangeOverTimeProjection::class, PriceChangeOverTimeProjection::class);
        $this->app->singleton(DbalConnectionFactory::class, fn() => new DbalConnectionFactory(getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@localhost:5432/ecotone'));
        $this->app->singleton('logger', fn() => new NullLogger());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
    }
}
