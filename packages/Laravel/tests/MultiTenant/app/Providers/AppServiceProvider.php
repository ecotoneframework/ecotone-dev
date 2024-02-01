<?php

namespace App\MultiTenant\Providers;

use Ecotone\Dbal\DbalConnection;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton('tenant_a_connection', function () {
            return DbalConnection::resolveLaravelConnection('tenant_a_connection');
        });
        $this->app->singleton('tenant_b_connection', function () {
            return DbalConnection::resolveLaravelConnection('tenant_b_connection');
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
    }
}
