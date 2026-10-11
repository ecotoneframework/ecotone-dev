<?php

declare(strict_types=1);

namespace Monorepo\CrossModuleTests\Tests\Tempest;

use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Messaging\ConfiguredMessagingSystem;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Tempest\EcotoneConfig;
use Tempest\Discovery\DiscoveryLocation;
use Test\Ecotone\Tempest\EcotoneIntegrationTestCase;
use Test\Ecotone\Tempest\TempestDatabaseConfigFactory;

/**
 * licence Apache-2.0
 * @internal
 */
final class DbalConnectionConnectivityTest extends EcotoneIntegrationTestCase
{
    protected function ecotoneConfig(): EcotoneConfig
    {
        return new EcotoneConfig(
            namespaces: ['Monorepo\\CrossModuleTests\\Fixture\\Tempest\\Dbal\\'],
            modulePackages: [ModulePackageList::TEMPEST_PACKAGE,
                ModulePackageList::DBAL_PACKAGE, ],
            test: false,
        );
    }

    protected function discoverTestLocations(): array
    {
        return [
            ...parent::discoverTestLocations(),
            new DiscoveryLocation(
                'Monorepo\\CrossModuleTests\\Fixture\\Tempest\\Dbal\\',
                dirname(__DIR__, 2) . '/Fixture/Tempest/Dbal',
            ),
        ];
    }

    public function test_dbal_connection_derived_from_tempest_postgres_config_executes_query(): void
    {
        $postgresConfig = TempestDatabaseConfigFactory::primary();

        $this->setupKernel();
        $this->container->config($postgresConfig);

        $messagingSystem = $this->container->get(ConfiguredMessagingSystem::class);
        $connectionFactory = $messagingSystem->getServiceFromContainer(DbalConnectionFactory::class);

        $result = $connectionFactory->createContext()->getDbalConnection()->executeQuery('SELECT 1 AS result')->fetchOne();

        $this->assertEquals(1, $result);
    }
}
