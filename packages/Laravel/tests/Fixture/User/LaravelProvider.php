<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\Fixture\User;

use Illuminate\Support\ServiceProvider;

/**
 * licence Apache-2.0
 */
final class LaravelProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->bind(UserService::class, UserService::class);
        $this->app->bind(MessagingConfiguration::class, MessagingConfiguration::class);
    }
}
