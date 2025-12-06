<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Enqueue\Dbal\DbalConnectionFactory;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
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
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
                ->withNamespaces([]),
            pathToRootCatalog: __DIR__ . '/../../',
        );
    }

    public function test_does_not_throw_when_dbal_connection_factory_is_configured(): void
    {
        $connectionFactory = DbalMessagingTestCase::prepareConnection();

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [DbalConnectionFactory::class => $connectionFactory],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
                ->withNamespaces([]),
            pathToRootCatalog: __DIR__ . '/../../',
        );

        self::assertNotNull($ecotone);
    }
}

