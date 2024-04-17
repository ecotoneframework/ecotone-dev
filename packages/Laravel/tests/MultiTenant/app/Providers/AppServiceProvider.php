<?php

namespace App\MultiTenant\Providers;

use App\MultiTenant\Application\NotificationSender;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(NotificationSender::class, function () {
            return new NotificationSender();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
    }
}
