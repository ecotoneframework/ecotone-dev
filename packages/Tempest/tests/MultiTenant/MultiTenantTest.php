<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\MultiTenant;

use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\QueryBus;
use Ecotone\Tempest\EcotoneConfig;
use Ecotone\Tempest\EcotoneServiceInitializer;
use Ecotone\Tempest\MessagingSystemInitializer;
use PDO;
use Test\Ecotone\Tempest\EcotoneIntegrationTestCase;
use Test\Ecotone\Tempest\Fixture\MultiTenant\RegisterCustomer;
use Test\Ecotone\Tempest\TempestDatabaseConfigFactory;

/**
 * licence Apache-2.0
 * @internal
 */
final class MultiTenantTest extends EcotoneIntegrationTestCase
{
    private CommandBus $commandBus;
    private QueryBus $queryBus;

    protected function ecotoneConfig(): EcotoneConfig
    {
        return new EcotoneConfig(
            namespaces: ['Test\\Ecotone\\Tempest\\Fixture\\MultiTenant\\'],
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
                'Test\\Ecotone\\Tempest\\Fixture\\MultiTenant\\',
                \Test\Ecotone\Tempest\TempestTestPaths::fixturePath() . '/MultiTenant',
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

        $this->createPersonsTableForBothTenants();

        $this->commandBus = $this->container->get(CommandBus::class);
        $this->queryBus = $this->container->get(QueryBus::class);
    }

    protected function tearDown(): void
    {
        $this->dropPersonsTableForBothTenants();
        parent::tearDown();
    }

    public function test_run_message_handlers_for_multi_tenant_connection(): void
    {
        $this->commandBus->send(new RegisterCustomer(1, 'John Doe'), metadata: ['tenant' => 'tenant_a']);
        $this->commandBus->send(new RegisterCustomer(2, 'John Doe'), metadata: ['tenant' => 'tenant_a']);
        $this->commandBus->send(new RegisterCustomer(2, 'John Doe'), metadata: ['tenant' => 'tenant_b']);

        $this->assertSame(
            [1, 2],
            $this->queryBus->sendWithRouting('customer.getAllRegistered', metadata: ['tenant' => 'tenant_a']),
        );

        $this->assertSame(
            [2],
            $this->queryBus->sendWithRouting('customer.getAllRegistered', metadata: ['tenant' => 'tenant_b']),
        );
    }

    public function test_using_dbal_based_business_interfaces(): void
    {
        $this->commandBus->sendWithRouting(
            'customer.register_with_business_interface',
            new RegisterCustomer(1, 'John Doe'),
            metadata: ['tenant' => 'tenant_a'],
        );

        $this->assertSame(
            [1],
            $this->queryBus->sendWithRouting('customer.getAllRegistered', metadata: ['tenant' => 'tenant_a']),
        );

        $this->assertSame(
            [],
            $this->queryBus->sendWithRouting('customer.getAllRegistered', metadata: ['tenant' => 'tenant_b']),
        );
    }

    private function createPersonsTableForBothTenants(): void
    {
        $this->createPersonsTable($this->postgresConnection());
        $this->createPersonsTable($this->mysqlConnection());
    }

    private function dropPersonsTableForBothTenants(): void
    {
        $this->postgresConnection()->exec('DROP TABLE IF EXISTS persons');
        $this->mysqlConnection()->exec('DROP TABLE IF EXISTS persons');
    }

    private function createPersonsTable(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS persons');
        $pdo->exec(
            'CREATE TABLE persons (
                customer_id INTEGER NOT NULL,
                name VARCHAR(255),
                PRIMARY KEY (customer_id)
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
