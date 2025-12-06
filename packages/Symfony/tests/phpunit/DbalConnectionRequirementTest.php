<?php

declare(strict_types=1);

namespace Test;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\SymfonyBundle\Config\SymfonyConnectionReference;
use Enqueue\Dbal\DbalConnectionFactory;
use PHPUnit\Framework\TestCase;
use Symfony\App\DbalConnectionRequirement\Kernel;

/**
 * Tests that Dbal module throws a user-friendly ConfigurationException when DbalConnectionFactory is not configured.
 *
 * @internal
 */
final class DbalConnectionRequirementTest extends TestCase
{
    public function test_throws_configuration_exception_when_dbal_connection_factory_is_not_configured(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("Dbal module requires 'Enqueue\Dbal\DbalConnectionFactory' to be configured");

        EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([
                    ModulePackageList::DBAL_PACKAGE,
                    ModulePackageList::SYMFONY_PACKAGE,
                ]))
                ->withNamespaces([]),
            pathToRootCatalog: __DIR__ . '/../../',
        );
    }

    public function test_does_not_throw_when_dbal_connection_factory_is_configured_directly(): void
    {
        $connectionFactory = $this->createMock(DbalConnectionFactory::class);

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [DbalConnectionFactory::class => $connectionFactory],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([
                    ModulePackageList::DBAL_PACKAGE,
                    ModulePackageList::SYMFONY_PACKAGE,
                ]))
                ->withNamespaces([]),
            pathToRootCatalog: __DIR__ . '/../../',
        );

        self::assertNotNull($ecotone);
    }

    public function test_does_not_throw_when_symfony_connection_reference_is_configured(): void
    {
        // Mock the doctrine registry that SymfonyConnectionModule uses
        $doctrineRegistry = new class {
            public function getConnection(string $_name): \Doctrine\DBAL\Connection
            {
                return \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: ['doctrine' => $doctrineRegistry],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([
                    ModulePackageList::DBAL_PACKAGE,
                    ModulePackageList::SYMFONY_PACKAGE,
                ]))
                ->withNamespaces([])
                ->withExtensionObjects([
                    SymfonyConnectionReference::defaultConnection('default'),
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
        );

        self::assertNotNull($ecotone);
    }

    public function test_real_symfony_application_throws_configuration_exception_when_dbal_connection_not_configured(): void
    {
        require_once __DIR__ . '/DbalConnectionRequirement/src/Kernel.php';

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("Dbal module requires 'Enqueue\Dbal\DbalConnectionFactory' to be configured");

        $kernel = new Kernel('test', true);
        $kernel->boot();
    }
}

