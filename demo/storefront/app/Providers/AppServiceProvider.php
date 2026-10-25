<?php

declare(strict_types=1);

namespace App\Providers;

use Ecotone\Api\Amqp\AmqpExtConnectionFactory;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AmqpExtConnectionFactory::class, fn () => new AmqpExtConnectionFactory(config('demo.amqp_dsn')));
    }
}
