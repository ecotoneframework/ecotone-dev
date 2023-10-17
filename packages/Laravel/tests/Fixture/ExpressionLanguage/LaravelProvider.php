<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\Fixture\ExpressionLanguage;

use Illuminate\Support\ServiceProvider;

final class LaravelProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->bind(ExpressionLanguageCommandHandler::class, ExpressionLanguageCommandHandler::class, true);
    }
}
