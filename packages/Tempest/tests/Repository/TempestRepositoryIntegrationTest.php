<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Repository;

use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Tempest\EcotoneConfig;
use Ecotone\Tempest\EcotoneServiceInitializer;
use Ecotone\Tempest\MessagingSystemInitializer;
use Tempest\Database\Database;
use Tempest\Database\Query;
use Tempest\Database\QueryStatements\CreateTableStatement;
use Test\Ecotone\Tempest\EcotoneIntegrationTestCase;
use Test\Ecotone\Tempest\Fixture\Order\Order;
use Test\Ecotone\Tempest\Fixture\Order\PlaceOrder;
use Test\Ecotone\Tempest\TempestDatabaseConfigFactory;
use Throwable;

/**
 * licence Apache-2.0
 * @internal
 */
final class TempestRepositoryIntegrationTest extends EcotoneIntegrationTestCase
{
    protected function ecotoneConfig(): EcotoneConfig
    {
        return new EcotoneConfig(
            namespaces: ['Test\\Ecotone\\Tempest\\Fixture\\Order\\'],
            skippedModulePackageNames: ModulePackageList::allPackages(),
            test: false,
        );
    }

    protected function setUp(): void
    {
        EcotoneServiceInitializer::clearCache();
        MessagingSystemInitializer::clearDefinitionHolder();

        $this->setupKernel();

        $postgresConfig = TempestDatabaseConfigFactory::primary();
        $this->container->config($postgresConfig);

        $this->createOrdersTable();
    }

    protected function tearDown(): void
    {
        $this->dropOrdersTable();
        parent::tearDown();
    }

    public function test_placing_an_order_with_tempest_model(): void
    {
        $commandBus = $this->container->get(\Ecotone\Modelling\CommandBus::class);

        $orderId = $commandBus->send(new PlaceOrder(userId: 'user-1', totalPrice: 100));

        $this->assertIsInt($orderId);
        $this->assertNotNull(Order::findById($orderId));
    }

    public function test_state_change_round_trips_through_command_and_query_bus(): void
    {
        $commandBus = $this->container->get(\Ecotone\Modelling\CommandBus::class);
        $queryBus = $this->container->get(\Ecotone\Modelling\QueryBus::class);

        $orderId = $commandBus->send(new PlaceOrder(userId: 'user-1', totalPrice: 100));

        $this->assertFalse(
            $queryBus->sendWithRouting('is_cancelled', metadata: ['aggregate.id' => $orderId]),
        );

        $commandBus->sendWithRouting('cancel_order', metadata: ['aggregate.id' => $orderId]);

        $this->assertTrue(
            $queryBus->sendWithRouting('is_cancelled', metadata: ['aggregate.id' => $orderId]),
        );
    }

    private function createOrdersTable(): void
    {
        $database = $this->container->get(Database::class);

        $database->execute(
            new Query('DROP TABLE IF EXISTS orders'),
        );

        $createSql = (new CreateTableStatement('orders'))
            ->primary('id')
            ->string('user_id')
            ->integer('total_price')
            ->boolean('is_cancelled')
            ->compile($database->dialect);

        $database->execute(new Query($createSql));
    }

    private function dropOrdersTable(): void
    {
        try {
            $database = $this->container->get(Database::class);
            $database->execute(new Query('DROP TABLE IF EXISTS orders'));
        } catch (Throwable) {
        }
    }
}
