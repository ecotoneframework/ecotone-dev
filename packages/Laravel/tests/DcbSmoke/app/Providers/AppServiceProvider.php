<?php

namespace App\DcbSmoke\Laravel\Providers;

use App\DcbSmoke\Laravel\Application\CouponService;
use App\DcbSmoke\Laravel\Application\EventsConverter;
use App\DcbSmoke\Laravel\Configuration\EcotoneConfiguration;
use Illuminate\Support\ServiceProvider;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
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
