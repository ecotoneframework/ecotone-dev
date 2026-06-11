<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\MultiTenant;

use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Modelling\CommandBus;
use Ecotone\Tempest\EcotoneConfig;
use Ecotone\Tempest\EcotoneServiceInitializer;
use Ecotone\Tempest\MessagingSystemInitializer;
use PDO;
use Test\Ecotone\Tempest\EcotoneIntegrationTestCase;
use Test\Ecotone\Tempest\Fixture\TenantAggregate\RegisterTenantProduct;
use Test\Ecotone\Tempest\TempestDatabaseConfigFactory;
use Test\Ecotone\Tempest\TempestTestPaths;

/**
 * licence Apache-2.0
 * @internal
 */
final class TenantAggregatePersistenceTest extends EcotoneIntegrationTestCase
{
    private CommandBus $commandBus;

    protected function ecotoneConfig(): EcotoneConfig
    {
        return new EcotoneConfig(
            namespaces: ['Test\\Ecotone\\Tempest\\Fixture\\TenantAggregate\\'],
            skippedModulePackageNames: ModulePackageList::allPackagesExcept([
                ModulePackageList::TEMPEST_PACKAGE,
                ModulePackageList::DBAL_PACKAGE,
            ]),
            test: false,
        );
    }

    protected function discoverTestLocations(): array
    {
        return [
            ...parent::discoverTestLocations(),
            new \Tempest\Discovery\DiscoveryLocation(
                'Test\\Ecotone\\Tempest\\Fixture\\TenantAggregate\\',
                TempestTestPaths::fixturePath() . '/TenantAggregate',
            ),
        ];
    }

    protected function setUp(): void
    {
        EcotoneServiceInitializer::clearCache();
        MessagingSystemInitializer::clearDefinitionHolder();

        $this->setupKernel();

        $this->container->config(TempestDatabaseConfigFactory::primary('tenant_a'));
        $this->container->config(TempestDatabaseConfigFactory::secondary('tenant_b'));

        $this->createTenantProductsTable($this->postgresConnection());
        $this->createTenantProductsTable($this->mysqlConnection());

        $this->commandBus = $this->container->get(CommandBus::class);
    }

    protected function tearDown(): void
    {
        $this->postgresConnection()->exec('DROP TABLE IF EXISTS tenant_products');
        $this->mysqlConnection()->exec('DROP TABLE IF EXISTS tenant_products');
        parent::tearDown();
    }

    public function test_tempest_model_aggregate_persists_to_the_active_tenant(): void
    {
        $this->commandBus->send(new RegisterTenantProduct('Alice'), metadata: ['tenant' => 'tenant_a']);
        $this->commandBus->send(new RegisterTenantProduct('Bob'), metadata: ['tenant' => 'tenant_a']);
        $this->commandBus->send(new RegisterTenantProduct('Carol'), metadata: ['tenant' => 'tenant_b']);

        $this->assertSame(['Alice', 'Bob'], $this->registeredNames($this->postgresConnection()));
        $this->assertSame(['Carol'], $this->registeredNames($this->mysqlConnection()));
    }

    private function registeredNames(PDO $pdo): array
    {
        return $pdo->query('SELECT name FROM tenant_products ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
    }

    private function createTenantProductsTable(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS tenant_products');
        $pdo->exec(
            'CREATE TABLE tenant_products (
                id VARCHAR(36) NOT NULL,
                name VARCHAR(255) NOT NULL,
                PRIMARY KEY (id)
            )',
        );
    }

    private function postgresConnection(): PDO
    {
        $config = TempestDatabaseConfigFactory::primary();

        return new PDO($config->dsn, $config->username, $config->password);
    }

    private function mysqlConnection(): PDO
    {
        $config = TempestDatabaseConfigFactory::secondary();

        return new PDO($config->dsn, $config->username, $config->password);
    }
}
