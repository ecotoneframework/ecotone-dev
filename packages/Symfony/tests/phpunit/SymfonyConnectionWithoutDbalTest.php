<?php

declare(strict_types=1);

namespace Test;

use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Symfony\SymfonyConnectionReference;
use Ecotone\Messaging\Config\ConfigurationException;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
#[RunTestsInSeparateProcesses]
final class SymfonyConnectionWithoutDbalTest extends TestCase
{
    protected function setUp(): void
    {
        self::hideEcotoneDbalFromTheAutoloader();
    }

    public function test_a_symfony_connection_reference_is_refused_at_bootstrap_when_ecotone_dbal_is_not_installed(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(self::refusalFor("SymfonyConnectionReference::createForConnection('reporting')"));

        EcotoneLite::bootstrapFlowTesting(
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::SYMFONY_PACKAGE])
                ->withExtensionObjects([SymfonyConnectionReference::createForConnection('reporting')]),
        );
    }

    public function test_a_manager_registry_connection_reference_is_refused_at_bootstrap_when_ecotone_dbal_is_not_installed(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(self::refusalFor("SymfonyConnectionReference::createForManagerRegistry('reporting')"));

        EcotoneLite::bootstrapFlowTesting(
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::SYMFONY_PACKAGE])
                ->withExtensionObjects([SymfonyConnectionReference::createForManagerRegistry('reporting')]),
        );
    }

    public function test_the_default_connection_is_refused_at_bootstrap_when_ecotone_dbal_is_not_installed(): void
    {
        $configuration = new class () {
            #[ServiceContext]
            public function connection(): SymfonyConnectionReference
            {
                return SymfonyConnectionReference::defaultConnection('default');
            }
        };

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(self::refusalFor("SymfonyConnectionReference::defaultConnection('default')"));

        EcotoneLite::bootstrapFlowTesting(
            [$configuration::class],
            [$configuration],
            ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::SYMFONY_PACKAGE]),
        );
    }

    public function test_the_default_manager_registry_connection_is_refused_at_bootstrap_when_ecotone_dbal_is_not_installed(): void
    {
        $configuration = new class () {
            #[ServiceContext]
            public function connection(): SymfonyConnectionReference
            {
                return SymfonyConnectionReference::defaultManagerRegistry('default');
            }
        };

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(self::refusalFor("SymfonyConnectionReference::defaultManagerRegistry('default')"));

        EcotoneLite::bootstrapFlowTesting(
            [$configuration::class],
            [$configuration],
            ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::SYMFONY_PACKAGE]),
        );
    }

    private static function refusalFor(string $connectionReference): string
    {
        return "{$connectionReference} hands a Doctrine connection to Ecotone, but ecotone/dbal is not installed. "
            . 'Only the Database capabilities ecotone/dbal brings read a connection reference — DBAL transactions, the Outbox, the Dead Letter, the Document Store, multi-tenant connections and the event store — so without it the connection has nothing to serve. '
            . 'Either install it with `composer require ecotone/dbal`, '
            . 'or remove the SymfonyConnectionReference from your #[ServiceContext] configuration.';
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
