<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\InMemory;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Enqueue\Dbal\DbalConnectionFactory;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\MetadataPropagationWithAsyncProjection\NotificationService;
use Test\Ecotone\EventSourcing\Fixture\MetadataPropagationWithAsyncProjection\OrderEventsConverter;
use Test\Ecotone\EventSourcing\Fixture\MetadataPropagationWithAsyncProjection\OrderProjection;

/**
 * @internal
 */
final class ProjectionMetadataPropagationTest extends EventSourcingMessagingTestCase
{
    public function test_metadata_propagation_with_synchronous_projection(): void
    {
        $ecotoneLite = $this->getBootstrapFlowTesting(
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::DBAL_PACKAGE]))
                ->withNamespaces(['Test\Ecotone\EventSourcing\Fixture\MetadataPropagationWithAsyncProjection'])
        );

        $ecotoneLite->sendCommandWithRoutingKey(routingKey: 'order.create', command: 1, metadata: ['foo' => 'bar', 'eventId' => 1]);
        self::assertEquals(expected: 2, actual: $ecotoneLite->sendQueryWithRouting('foo_orders.count'));
        self::assertEquals(expected: 2, actual: $ecotoneLite->sendQueryWithRouting('getNotificationCountWithFoo'));

        $ecotoneLite->sendCommandWithRoutingKey(routingKey: 'order.create', command: 2, metadata: ['eventId' => 2]);
        self::assertEquals(expected: 2, actual: $ecotoneLite->sendQueryWithRouting('foo_orders.count'));
        self::assertEquals(expected: 2, actual: $ecotoneLite->sendQueryWithRouting('getNotificationCountWithFoo'));

        $ecotoneLite->sendCommandWithRoutingKey(routingKey: 'order.create', command: 3, metadata: ['foo' => 'baz', 'eventId' => 3]);
        self::assertEquals(expected: 4, actual: $ecotoneLite->sendQueryWithRouting('foo_orders.count'));
        self::assertEquals(expected: 4, actual: $ecotoneLite->sendQueryWithRouting('getNotificationCountWithFoo'));
    }

    public function test_metadata_propagation_with_async_projection_when_catching_up(): void
    {
        $ecotoneLite = $this->getBootstrapFlowTesting(
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withNamespaces(['Test\Ecotone\EventSourcing\Fixture\MetadataPropagationWithAsyncProjection'])
                ->withExtensionObjects([
                    SimpleMessageChannelBuilder::createQueueChannel(OrderProjection::CHANNEL),
                ])
        );

        $ecotoneLite->sendCommandWithRoutingKey(routingKey: 'order.create', command: 1, metadata: ['foo' => 'bar', 'eventId' => 1]);
        $ecotoneLite->sendCommandWithRoutingKey(routingKey: 'order.create', command: 2, metadata: ['eventId' => 2]);
        $ecotoneLite->sendCommandWithRoutingKey(routingKey: 'order.create', command: 3, metadata: ['foo' => 'baz', 'eventId' => 3]);

        $ecotoneLite->run(name: OrderProjection::CHANNEL, executionPollingMetadata: ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 10, maxExecutionTimeInMilliseconds: 1000));

        self::assertEquals(expected: 4, actual: $ecotoneLite->sendQueryWithRouting('foo_orders.count'));
        self::assertEquals(expected: 4, actual: $ecotoneLite->sendQueryWithRouting('getNotificationCountWithFoo'));
    }

    public function test_metadata_propagation_with_async_projection_when_populated_dynamically(): void
    {
        $ecotoneLite = $this->getBootstrapFlowTesting(
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withNamespaces(['Test\Ecotone\EventSourcing\Fixture\MetadataPropagationWithAsyncProjection'])
                ->withExtensionObjects([
                    SimpleMessageChannelBuilder::createQueueChannel(OrderProjection::CHANNEL),
                ])
        );

        $ecotoneLite->sendCommandWithRoutingKey(routingKey: 'order.create', command: 1, metadata: ['foo' => 'bar']);
        $ecotoneLite->run(name: OrderProjection::CHANNEL, executionPollingMetadata: ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 4, maxExecutionTimeInMilliseconds: 1000));

        self::assertEquals(expected: 2, actual: $ecotoneLite->sendQueryWithRouting('foo_orders.count'));
        self::assertEquals(expected: 2, actual: $ecotoneLite->sendQueryWithRouting('getNotificationCountWithFoo'));

        $ecotoneLite->sendCommandWithRoutingKey(routingKey: 'order.create', command: 2);
        $ecotoneLite->run(name: OrderProjection::CHANNEL, executionPollingMetadata: ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 4, maxExecutionTimeInMilliseconds: 1000));

        self::assertEquals(expected: 2, actual: $ecotoneLite->sendQueryWithRouting('foo_orders.count'));
        self::assertEquals(expected: 2, actual: $ecotoneLite->sendQueryWithRouting('getNotificationCountWithFoo'));

        $ecotoneLite->sendCommandWithRoutingKey(routingKey: 'order.create', command: 3, metadata: ['foo' => 'baz']);
        $ecotoneLite->run(name: OrderProjection::CHANNEL, executionPollingMetadata: ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 4, maxExecutionTimeInMilliseconds: 1000));

        self::assertEquals(expected: 4, actual: $ecotoneLite->sendQueryWithRouting('foo_orders.count'));
        self::assertEquals(expected: 4, actual: $ecotoneLite->sendQueryWithRouting('getNotificationCountWithFoo'));
    }

    private function getBootstrapFlowTesting(ServiceConfiguration $serviceConfiguration): FlowTestSupport
    {
        /** @var DbalConnectionFactory $connectionFactory */
        $connectionFactory = $this->getConnectionFactory();
        $connection = $connectionFactory->createContext()->getDbalConnection();
        $schemaManager = self::getSchemaManager($connection);
        if ($schemaManager->tablesExist(names: OrderProjection::TABLE)) {
            $connection->delete(OrderProjection::TABLE, ['1' => '1']);
        }

        return EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [new OrderProjection($connection), new OrderEventsConverter(), new NotificationService(), DbalConnectionFactory::class => $connectionFactory],
            configuration: $serviceConfiguration,
            pathToRootCatalog: __DIR__ . '/../../',
            addEventSourcedRepository: false
        );
    }
}
