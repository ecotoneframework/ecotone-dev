<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\MessageHeaders;
use Enqueue\Dbal\DbalConnectionFactory;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\Deduplication\Converter;
use Test\Ecotone\Dbal\Fixture\Deduplication\OrderPlaced;
use Test\Ecotone\Dbal\Fixture\Deduplication\OrderService;
use Test\Ecotone\Dbal\Fixture\Deduplication\OrderSubscriber;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class DeduplicationModuleTest extends DbalMessagingTestCase
{
    private const CHANNEL_NAME = 'processOrders';

    public function test_sending_same_command_will_deduplicate_it(): void
    {
        $ecotone = $this->bootstrapEcotone();

        $ecotone->sendCommandWithRoutingKey(routingKey: 'placeOrder', command: 'milk', metadata: [MessageHeaders::MESSAGE_ID => '3e84ff08-b755-4e16-b50d-94818bf9de99']);
        $ecotone->run(self::CHANNEL_NAME);
        $ecotone->sendCommandWithRoutingKey(routingKey: 'placeOrder', command: 'milk', metadata: [MessageHeaders::MESSAGE_ID => '3e84ff08-b755-4e16-b50d-94818bf9de99']);
        $ecotone->run(self::CHANNEL_NAME);

        $result = $ecotone->sendQueryWithRouting(routingKey: 'order.getRegistered');

        self::assertEquals(['milk'], $result);
        self::assertCount(1, $result);
    }

    public function test_sending_same_event_will_deduplicate_message(): void
    {
        $ecotone = $this->bootstrapEcotone();

        $ecotone->publishEvent(event: new OrderPlaced(order: 'milk'), metadata: [MessageHeaders::MESSAGE_ID => '3e84ff08-b755-4e16-b50d-94818bf9de99']);
        $ecotone->run(self::CHANNEL_NAME);
        $ecotone->publishEvent(event: new OrderPlaced(order: 'milk'), metadata: [MessageHeaders::MESSAGE_ID => '3e84ff08-b755-4e16-b50d-94818bf9de99']);
        $ecotone->run(self::CHANNEL_NAME);

        self::assertEquals(2, $ecotone->sendQueryWithRouting('order.getCalled'));
    }

    public function test_sending_different_commands_will_not_deduplicate_messages(): void
    {
        $ecotone = $this->bootstrapEcotone();

        $ecotone->sendCommandWithRoutingKey(routingKey: 'placeOrder', command: 'milk', metadata: [MessageHeaders::MESSAGE_ID => '3e84ff08-b755-4e16-b50d-94818bf9de99']);
        $ecotone->run(self::CHANNEL_NAME);
        $ecotone->sendCommandWithRoutingKey(routingKey: 'placeOrder', command: 'cheese', metadata: [MessageHeaders::MESSAGE_ID => '3e84ff08-b755-4e16-b50d-94818bf9de98']);
        $ecotone->run(self::CHANNEL_NAME);

        $result = $ecotone->sendQueryWithRouting(routingKey: 'order.getRegistered');

        self::assertEquals(['milk', 'cheese'], $result);
        self::assertCount(2, $result);
        self::assertEquals(4, $ecotone->sendQueryWithRouting('order.getCalled'));
    }

    public function test_sending_with_custom_deduplication_key()
    {
        $ecotone = $this->bootstrapEcotone();

        $sameDeduplicationKey = '3e84ff08-b755-4e16-b50d-94818bf9de99';
        $ecotone->sendCommandWithRoutingKey(
            routingKey: 'placeOrderSynchronously1',
            command: 'milk',
            metadata: ['orderId1' => $sameDeduplicationKey]
        );
        $ecotone->sendCommandWithRoutingKey(
            routingKey: 'placeOrderSynchronously1',
            command: 'cheese',
            metadata: ['orderId1' => $sameDeduplicationKey]
        );

        $result = $ecotone->sendQueryWithRouting(routingKey: 'order.getRegistered');

        self::assertEquals(['milk'], $result);
        self::assertCount(1, $result);
    }

    public function test_deduplication_happens_across_endpoint_id()
    {
        $ecotone = $this->bootstrapEcotone();

        $sameDeduplicationKey = '3e84ff08-b755-4e16-b50d-94818bf9de99';
        $ecotone->sendCommandWithRoutingKey(
            routingKey: 'placeOrderSynchronously1',
            command: 'milk',
            metadata: ['orderId1' => $sameDeduplicationKey]
        );
        $ecotone->sendCommandWithRoutingKey(
            routingKey: 'placeOrderSynchronously2',
            command: 'cheese',
            metadata: ['orderId2' => $sameDeduplicationKey]
        );

        $result = $ecotone->sendQueryWithRouting(routingKey: 'order.getRegistered');

        self::assertEquals(['milk', 'cheese'], $result);
        self::assertCount(2, $result);
    }

    public function test_deduplicating_within_same_key_for_different_endpoints()
    {
        $ecotone = $this->bootstrapEcotone();

        $sameDeduplicationKey = '3e84ff08-b755-4e16-b50d-94818bf9de99';
        $ecotone->sendCommandWithRoutingKey(
            routingKey: 'placeOrderSynchronously1',
            command: 'milk',
            metadata: ['orderId1' => $sameDeduplicationKey]
        );
        $ecotone->sendCommandWithRoutingKey(
            routingKey: 'placeOrderSynchronously3',
            command: 'cheese',
            metadata: ['orderId1' => $sameDeduplicationKey]
        );

        $result = $ecotone->sendQueryWithRouting(routingKey: 'order.getRegistered');

        self::assertEquals(['milk', 'cheese'], $result);
        self::assertCount(2, $result);
    }

    public function test_deduplication_inserts_before_handler_when_transaction_is_active(): void
    {
        // Create two separate connection factories pointing to the same database
        $dsn = getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@localhost:5432/ecotone';
        if (! str_contains($dsn, 'pgsql')) {
            $this->markTestSkipped('Not supported on PostgreSQL');

            return;
        }

        $connectionFactory1 = new DbalConnectionFactory($dsn);
        $connectionFactory2 = new DbalConnectionFactory($dsn);

        // Set lock timeout on connection 2 to avoid hanging
        // We need to set it on the actual connection that will be used
        $connection2 = $connectionFactory2->createContext()->getDbalConnection();
        $platform = $connection2->getDatabasePlatform();

        // Set global lock timeout for the session
        if ($platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
            $connection2->executeStatement('SET lock_timeout = 1000'); // 1 second
        } elseif ($platform instanceof \Doctrine\DBAL\Platforms\MySQLPlatform) {
            // For MySQL, we need to set this on the session level
            $connection2->executeStatement('SET SESSION innodb_lock_wait_timeout = 1'); // 1 second
        }

        // Close the connection so it will be reopened with the settings when needed
        $connection2->close();

        // Bootstrap first Ecotone instance with transactions enabled
        $orderService1 = new OrderService();
        $ecotone1 = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [OrderService::class, OrderSubscriber::class, Converter::class],
            containerOrAvailableServices: [$orderService1, new OrderSubscriber(), new Converter(), DbalConnectionFactory::class => $connectionFactory1],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withDefaultSerializationMediaType(MediaType::createApplicationJson())
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    \Ecotone\Dbal\Configuration\DbalConfiguration::createWithDefaults()
                        ->withTransactionOnCommandBus(true)
                        ->withDeduplication(true),
                ])
                ->withCacheDirectoryPath(sys_get_temp_dir() . '/ecotone-test-' . uniqid()),
            pathToRootCatalog: __DIR__ . '/../../',
        );

        // Bootstrap second Ecotone instance
        $orderService2 = new OrderService();
        $ecotone2 = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [OrderService::class, OrderSubscriber::class, Converter::class],
            containerOrAvailableServices: [$orderService2, new OrderSubscriber(), new Converter(), DbalConnectionFactory::class => $connectionFactory2],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withDefaultSerializationMediaType(MediaType::createApplicationJson())
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    \Ecotone\Dbal\Configuration\DbalConfiguration::createWithDefaults()
                        ->withTransactionOnCommandBus(true)
                        ->withDeduplication(true),
                ])
                ->withCacheDirectoryPath(sys_get_temp_dir() . '/ecotone-test-' . uniqid()),
            pathToRootCatalog: __DIR__ . '/../../',
        );

        $messageId = '3e84ff08-b755-4e16-b50d-94818bf9de99';

        // Send command from instance 1 with transaction enabled
        // Deduplication should be inserted BEFORE handler due to transaction
        $ecotone1->sendCommandWithRoutingKey(
            routingKey: 'placeOrderSynchronously1',
            command: 'milk',
            metadata: ['orderId1' => $messageId]
        );

        // Send the same command from instance 2
        // This should be deduplicated and NOT execute the handler
        $ecotone2->sendCommandWithRoutingKey(
            routingKey: 'placeOrderSynchronously1',
            command: 'cheese',
            metadata: ['orderId1' => $messageId]
        );

        // Verify handler was called only once (from instance 1)
        self::assertCount(1, $ecotone1->sendQueryWithRouting('order.getRegistered'));
        self::assertEquals(['milk'], $ecotone1->sendQueryWithRouting('order.getRegistered'));

        // Instance 2's handler should not have been called
        self::assertCount(0, $ecotone2->sendQueryWithRouting('order.getRegistered'));
    }

    public function test_deduplication_inserts_after_handler_when_no_transaction_is_active(): void
    {
        // Create two separate connection factories pointing to the same database
        $dsn = getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@localhost:5432/ecotone';
        if (! str_contains($dsn, 'pgsql')) {
            $this->markTestSkipped('Not supported on PostgreSQL');

            return;
        }

        $connectionFactory1 = new DbalConnectionFactory($dsn);
        $connectionFactory2 = new DbalConnectionFactory($dsn);

        // Bootstrap first Ecotone instance WITHOUT transactions
        $orderService1 = new OrderService();
        $ecotone1 = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [OrderService::class, OrderSubscriber::class, Converter::class],
            containerOrAvailableServices: [$orderService1, new OrderSubscriber(), new Converter(), DbalConnectionFactory::class => $connectionFactory1],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withDefaultSerializationMediaType(MediaType::createApplicationJson())
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    \Ecotone\Dbal\Configuration\DbalConfiguration::createWithDefaults()
                        ->withTransactionOnCommandBus(false)
                        ->withDeduplication(true),
                ])
                ->withCacheDirectoryPath(sys_get_temp_dir() . '/ecotone-test-' . uniqid()),
            pathToRootCatalog: __DIR__ . '/../../',
        );

        // Bootstrap second Ecotone instance WITHOUT transactions
        $orderService2 = new OrderService();
        $ecotone2 = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [OrderService::class, OrderSubscriber::class, Converter::class],
            containerOrAvailableServices: [$orderService2, new OrderSubscriber(), new Converter(), DbalConnectionFactory::class => $connectionFactory2],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withDefaultSerializationMediaType(MediaType::createApplicationJson())
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    \Ecotone\Dbal\Configuration\DbalConfiguration::createWithDefaults()
                        ->withTransactionOnCommandBus(false)
                        ->withDeduplication(true),
                ])
                ->withCacheDirectoryPath(sys_get_temp_dir() . '/ecotone-test-' . uniqid()),
            pathToRootCatalog: __DIR__ . '/../../',
        );

        $messageId = '3e84ff08-b755-4e16-b50d-94818bf9de88';

        // Send command from instance 1 (handler executes FIRST, then deduplication inserted)
        $ecotone1->sendCommandWithRoutingKey(
            routingKey: 'placeOrderSynchronously1',
            command: 'milk',
            metadata: ['orderId1' => $messageId]
        );

        // Send the same command from instance 2
        // This should be deduplicated and NOT execute the handler
        $ecotone2->sendCommandWithRoutingKey(
            routingKey: 'placeOrderSynchronously1',
            command: 'cheese',
            metadata: ['orderId1' => $messageId]
        );

        // Verify handler was called only once (from instance 1)
        self::assertCount(1, $ecotone1->sendQueryWithRouting('order.getRegistered'));
        self::assertEquals(['milk'], $ecotone1->sendQueryWithRouting('order.getRegistered'));

        // Instance 2's handler should not have been called due to deduplication
        self::assertCount(0, $ecotone2->sendQueryWithRouting('order.getRegistered'));
    }

    private function bootstrapEcotone(): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [new OrderService(), new OrderSubscriber(), new Converter(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withDefaultSerializationMediaType(MediaType::createApplicationJson())
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withNamespaces([
                    'Test\Ecotone\Dbal\Fixture\Deduplication',
                ])
                ->withCacheDirectoryPath(sys_get_temp_dir() . '/ecotone-test-' . uniqid()),
            pathToRootCatalog: __DIR__ . '/../../',
        );
    }
}
