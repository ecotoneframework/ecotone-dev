<?php

declare(strict_types=1);

namespace App\Providers;

use App\Web\CurrentCustomer;
use Ecotone\Api\Amqp\AmqpExtConnectionFactory;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View as RenderedView;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\API\Trace\TracerProviderInterface;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AmqpExtConnectionFactory::class, fn () => new AmqpExtConnectionFactory(config('demo.amqp_dsn')));
        $this->app->singleton(TracerProviderInterface::class, $this->app->environment('testing')
            ? fn () => new NoopTracerProvider()
            : fn () => Globals::tracerProvider());
    }

    public function boot(): void
    {
        View::composer('layouts.app', fn (RenderedView $view) => $view->with('currentCustomer', $this->app->make(CurrentCustomer::class)->of(request())));
    }
}
