<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\Fixture\ExpressionLanguage;

use Illuminate\Support\ServiceProvider;

/**
 * licence Apache-2.0
 */
final class LaravelProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->bind(ExpressionLanguageCommandHandler::class, ExpressionLanguageCommandHandler::class, true);
        $this->app->bind(CalculatorService::class, CalculatorService::class, true);
    }
}
