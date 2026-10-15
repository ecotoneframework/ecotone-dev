<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel;

use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Laravel\LaravelConnectionReference;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ConfigurationException;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
#[RunTestsInSeparateProcesses]
final class LaravelConnectionWithoutDbalTest extends TestCase
{
    protected function setUp(): void
    {
        self::hideEcotoneDbalFromTheAutoloader();
    }

    public function test_a_laravel_connection_reference_is_refused_at_bootstrap_when_ecotone_dbal_is_not_installed(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(self::refusalFor("LaravelConnectionReference::create('reporting')"));

        EcotoneLite::bootstrapFlowTesting(extensionObjects: [LaravelConnectionReference::create('reporting')], enabledModules: [ModulePackageList::LARAVEL_PACKAGE]);
    }

    public function test_the_default_laravel_connection_is_refused_at_bootstrap_when_ecotone_dbal_is_not_installed(): void
    {
        $configuration = new class () {
            #[ServiceContext]
            public function connection(): LaravelConnectionReference
            {
                return LaravelConnectionReference::defaultConnection('mysql');
            }
        };

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(self::refusalFor("LaravelConnectionReference::defaultConnection('mysql')"));

        EcotoneLite::bootstrapFlowTesting(
            [$configuration::class],
            [$configuration],
            enabledModules: [ModulePackageList::LARAVEL_PACKAGE],
        );
    }

    private static function refusalFor(string $connectionReference): string
    {
        return "{$connectionReference} hands a Laravel database connection to Ecotone, but ecotone/dbal is not installed. "
            . 'Only the Database capabilities ecotone/dbal brings read a connection reference — DBAL transactions, the Outbox, the Dead Letter, the Document Store, multi-tenant connections and the event store — so without it the connection has nothing to serve. '
            . 'Either install it with `composer require ecotone/dbal`, '
            . 'or remove the LaravelConnectionReference from your #[ServiceContext] configuration.';
    }

    private static function hideEcotoneDbalFromTheAutoloader(): void
    {
        foreach (spl_autoload_functions() as $autoloader) {
            spl_autoload_unregister($autoloader);
            spl_autoload_register(static function (string $class) use ($autoloader): void {
                if (! str_starts_with($class, 'Ecotone\\Dbal\\') && ! str_starts_with($class, 'Ecotone\\Api\\Dbal\\')) {
                    $autoloader($class);
                }
            });
        }
    }
}
