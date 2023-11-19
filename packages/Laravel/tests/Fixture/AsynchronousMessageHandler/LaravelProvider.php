<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\Fixture\AsynchronousMessageHandler;

use Illuminate\Support\ServiceProvider;

final class LaravelProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->bind(AsyncCommandHandler::class, AsyncCommandHandler::class);
        $this->app->bind(AsyncEventHandler::class, AsyncEventHandler::class);
    }
}
