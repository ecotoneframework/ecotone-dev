<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Dbal;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Tempest\EcotoneConfig;
use Enqueue\Dbal\DbalConnectionFactory;
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
            namespaces: ['Test\\Ecotone\\Tempest\\Fixture\\Dbal\\'],
            skippedModulePackageNames: ModulePackageList::allPackagesExcept([
                ModulePackageList::TEMPEST_PACKAGE,
                ModulePackageList::DBAL_PACKAGE,
            ]),
            test: false,
        );
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
