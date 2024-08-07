<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\Fixture\Order;

use Illuminate\Support\ServiceProvider;

/**
 * licence Apache-2.0
 */
final class LaravelProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->bind(OrderService::class, OrderService::class);
    }
}
