<?php

declare(strict_types=1);

namespace Monorepo\SwooleApp\Laravel\app\Providers;

use Ecotone\Api\Dbal\DbalConnectionFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Monorepo\SwooleApp\Common\Probe;
use Psr\Log\NullLogger;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DbalConnectionFactory::class, fn () => new DbalConnectionFactory((string) getenv('DATABASE_DSN')));
        $this->app->singleton('logger', fn () => new NullLogger());
    }

    public function boot(): void
    {
        Route::post('/request-scope', fn (\Ecotone\Api\Gateway\QueryBus $queryBus) => response()->json($queryBus->sendWithRouting('laravel.requestScope')));
        Route::post('/{action}', fn (Request $request, string $action, Probe $probe) => response()->json(
            $probe->handle($action, json_decode($request->getContent() ?: '[]', true) + $request->query->all())
        ));
    }
}
