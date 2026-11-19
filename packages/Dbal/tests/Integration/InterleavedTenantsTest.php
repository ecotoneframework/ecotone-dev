<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Doctrine\DBAL\Connection;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Dbal\Attribute\CurrentConnection;
use Ecotone\Api\Dbal\Attribute\OnTenantActivation;
use Ecotone\Api\Dbal\Attribute\OnTenantDeactivation;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\MultiTenantConfiguration;
use Ecotone\Api\ExtensionObject\ConnectionReference;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Test\LicenceTesting;
use Fiber;
use Interop\Queue\ConnectionFactory;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class InterleavedTenantsTest extends DbalMessagingTestCase
{
    public const TABLE = 'interleaved_tenant_orders';

    public function setUp(): void
    {
        parent::setUp();

        foreach ([$this->connectionForTenantA(), $this->connectionForTenantB()] as $connectionFactory) {
            $connection = $connectionFactory->createContext()->getDbalConnection();
            $connection->executeStatement('DROP TABLE IF EXISTS ' . self::TABLE);
            $connection->executeStatement('CREATE TABLE ' . self::TABLE . ' (order_id VARCHAR(255))');
        }
    }

    public function test_suspended_execution_stores_in_its_own_tenant_database_after_an_execution_for_another_tenant_has_run(): void
    {
        [$ecotone] = $this->bootstrapOrders();

        $suspended = new Fiber(fn () => $ecotone->sendCommandWithRouting('order.place', ['orderId' => 'order-of-tenant-a', 'suspend' => true], metadata: ['tenant' => 'tenant_a']));
        $suspended->start();
        $ecotone->sendCommandWithRouting('order.place', ['orderId' => 'order-of-tenant-b', 'suspend' => false], metadata: ['tenant' => 'tenant_b']);
        $suspended->resume();

        $this->assertTrue($suspended->isTerminated());
        $this->assertSame(['order-of-tenant-a'], $this->ordersIn($this->connectionForTenantA()));
        $this->assertSame(['order-of-tenant-b'], $this->ordersIn($this->connectionForTenantB()));
    }

    public function test_event_handler_of_a_resumed_execution_stores_in_its_own_tenant_database(): void
    {
        [$ecotone] = $this->bootstrapOrders();

        $suspended = new Fiber(fn () => $ecotone->sendCommandWithRouting('order.placeAndPublish', ['orderId' => 'order-of-tenant-a', 'suspend' => true], metadata: ['tenant' => 'tenant_a']));
        $suspended->start();
        $ecotone->sendCommandWithRouting('order.placeAndPublish', ['orderId' => 'order-of-tenant-b', 'suspend' => false], metadata: ['tenant' => 'tenant_b']);
        $suspended->resume();

        $this->assertTrue($suspended->isTerminated());
        $this->assertSame(['order-of-tenant-a'], $this->ordersIn($this->connectionForTenantA()));
        $this->assertSame(['order-of-tenant-b'], $this->ordersIn($this->connectionForTenantB()));
    }

    public function test_every_execution_activates_and_deactivates_its_tenant_even_when_another_execution_has_activated_the_same_tenant(): void
    {
        [$ecotone, $orders] = $this->bootstrapOrders();

        $suspended = new Fiber(fn () => $ecotone->sendCommandWithRouting('order.place', ['orderId' => 'first', 'suspend' => true], metadata: ['tenant' => 'tenant_a']));
        $suspended->start();
        $ecotone->sendCommandWithRouting('order.place', ['orderId' => 'second', 'suspend' => false], metadata: ['tenant' => 'tenant_a']);
        $suspended->resume();

        $this->assertSame(
            ['activated tenant_a', 'activated tenant_a', 'stored second', 'deactivated tenant_a', 'stored first', 'deactivated tenant_a'],
            $orders->steps
        );
        $this->assertSame(['first', 'second'], $this->ordersIn($this->connectionForTenantA()));
    }

    private function ordersIn(ConnectionFactory $connectionFactory): array
    {
        return $connectionFactory->createContext()->getDbalConnection()
            ->executeQuery('SELECT order_id FROM ' . self::TABLE . ' ORDER BY order_id')
            ->fetchFirstColumn();
    }

    private function bootstrapOrders(): array
    {
        $orders = new class () {
            public array $steps = [];

            #[CommandHandler('order.place')]
            public function place(array $command, #[CurrentConnection] Connection $connection): void
            {
                if ($command['suspend']) {
                    Fiber::suspend();
                }

                $connection->insert(InterleavedTenantsTest::TABLE, ['order_id' => $command['orderId']]);
                $this->steps[] = "stored {$command['orderId']}";
            }

            #[CommandHandler('order.placeAndPublish')]
            public function placeAndPublish(array $command, EventBus $eventBus): void
            {
                if ($command['suspend']) {
                    Fiber::suspend();
                }

                $eventBus->publish(new TenantOrderWasPlaced($command['orderId']));
            }

            #[EventHandler]
            public function store(TenantOrderWasPlaced $event, #[CurrentConnection] Connection $connection): void
            {
                $connection->insert(InterleavedTenantsTest::TABLE, ['order_id' => $event->orderId]);
            }

            #[OnTenantActivation]
            public function activated(string|ConnectionReference $tenantConnection, #[Header('tenant')] string $tenant): void
            {
                $this->steps[] = "activated {$tenant}";
            }

            #[OnTenantDeactivation]
            public function deactivated(string|ConnectionReference $tenantConnection, #[Header('tenant')] string $tenant): void
            {
                $this->steps[] = "deactivated {$tenant}";
            }
        };

        $ecotone = $this->bootstrapFlowTesting(
            [$orders::class],
            [
                $orders,
                'tenant_a_connection' => $this->connectionForTenantA(),
                'tenant_b_connection' => $this->connectionForTenantB(),
            ],
            extensionObjects: [
                MultiTenantConfiguration::create(tenantHeaderName: 'tenant', tenantToConnectionMapping: ['tenant_a' => 'tenant_a_connection', 'tenant_b' => 'tenant_b_connection']),
                DbalConfiguration::createWithDefaults()->withDeduplication(false),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        return [$ecotone, $orders];
    }
}

final class TenantOrderWasPlaced
{
    public function __construct(public string $orderId)
    {
    }
}
