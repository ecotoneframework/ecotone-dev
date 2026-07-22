<?php

declare(strict_types=1);

namespace App\MissingReference\Laravel;

use App\MissingReference\Laravel\Shared\MissingServiceContract;
use Illuminate\Support\ServiceProvider;

/**
 * Registers MissingServiceContract through a factory binding when the test
 * asks for it. The binding is resolved lazily — only when a dispatched
 * message actually needs the service.
 *
 * licence Apache-2.0
 */
final class FactoryRegistrationProvider extends ServiceProvider
{
    public static bool $factoryInvoked = false;

    public function register(): void
    {
        if (getenv('ECOTONE_MISSING_REF_REGISTER_FACTORY') !== '1') {
            return;
        }

        $this->app->bind(MissingServiceContract::class, function (): MissingServiceContract {
            self::$factoryInvoked = true;

            return new class implements MissingServiceContract {
            };
        });
    }
}
