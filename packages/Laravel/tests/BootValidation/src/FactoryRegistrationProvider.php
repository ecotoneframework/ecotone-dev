<?php

declare(strict_types=1);

namespace App\BootValidation\Laravel;

use App\BootValidation\Laravel\Shared\MissingServiceContract;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Registers MissingServiceContract through a factory binding when the test
 * asks for it. Boot validation must accept the binding as proof of
 * availability WITHOUT invoking it — resolving here would be the equivalent
 * of opening a database connection at compile time.
 *
 * licence Apache-2.0
 */
final class FactoryRegistrationProvider extends ServiceProvider
{
    public static bool $factoryInvoked = false;

    public function register(): void
    {
        if (getenv('ECOTONE_BOOT_REGISTER_FACTORY') !== '1') {
            return;
        }

        $this->app->bind(MissingServiceContract::class, function (): never {
            self::$factoryInvoked = true;

            throw new RuntimeException('The factory must not be invoked during boot validation');
        });
    }
}
