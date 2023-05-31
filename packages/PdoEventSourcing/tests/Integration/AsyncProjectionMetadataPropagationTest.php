<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Enqueue\Dbal\DbalConnectionFactory;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTest;
use Test\Ecotone\EventSourcing\Fixture\MetadataPropagationWithAsyncProjection\OrderEventsConverter;
use Test\Ecotone\EventSourcing\Fixture\MetadataPropagationWithAsyncProjection\OrderProjection;

final class AsyncProjectionMetadataPropagationTest extends EventSourcingMessagingTest
{
    public function test_metadata_propagation_with_async_projection_when_catching_up(): void
    {
        $ecotoneLite = $this->getBootstrapFlowTesting();

        $ecotoneLite->sendCommandWithRoutingKey(routingKey: 'order.create', command: 1, metadata: ['foo' => 'bar']);
        $ecotoneLite->sendCommandWithRoutingKey(routingKey: 'order.create', command: 2);
        $ecotoneLite->sendCommandWithRoutingKey(routingKey: 'order.create', command: 3, metadata: ['foo' => 'baz']);

        $ecotoneLite->run(name: OrderProjection::CHANNEL, executionPollingMetadata: ExecutionPollingMetadata::createWithTestingSetup());

        self::assertEquals(expected: 4, actual: $ecotoneLite->sendQueryWithRouting('foo_orders.count'));
    }

    public function test_metadata_propagation_with_async_projection_when_populated_dynamically(): void
    {
        $ecotoneLite = $this->getBootstrapFlowTesting();

        $ecotoneLite->sendCommandWithRoutingKey(routingKey: 'order.create', command: 1, metadata: ['foo' => 'bar']);
        $ecotoneLite->run(name: OrderProjection::CHANNEL, executionPollingMetadata: ExecutionPollingMetadata::createWithTestingSetup());
        self::assertEquals(expected: 2, actual: $ecotoneLite->sendQueryWithRouting('foo_orders.count'));

        $ecotoneLite->sendCommandWithRoutingKey(routingKey: 'order.create', command: 2);
        $ecotoneLite->run(name: OrderProjection::CHANNEL, executionPollingMetadata: ExecutionPollingMetadata::createWithTestingSetup());
        self::assertEquals(expected: 2, actual: $ecotoneLite->sendQueryWithRouting('foo_orders.count'));

        $ecotoneLite->sendCommandWithRoutingKey(routingKey: 'order.create', command: 3, metadata: ['foo' => 'baz']);
        $ecotoneLite->run(name: OrderProjection::CHANNEL, executionPollingMetadata: ExecutionPollingMetadata::createWithTestingSetup());
        self::assertEquals(expected: 4, actual: $ecotoneLite->sendQueryWithRouting('foo_orders.count'));
    }

    private function getBootstrapFlowTesting(): FlowTestSupport
    {
        /** @var DbalConnectionFactory $connectionFactory */
        $connectionFactory = $this->getConnectionFactory();
        $connection = $connectionFactory->createContext()->getDbalConnection();
        $schemaManager = $connection->createSchemaManager();
        if ($schemaManager->tablesExist(names: OrderProjection::TABLE)) {
            $schemaManager->dropTable(name: OrderProjection::TABLE);
        }

        return EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [new OrderProjection($connection), new OrderEventsConverter(), DbalConnectionFactory::class => $connectionFactory],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withNamespaces(['Test\Ecotone\EventSourcing\Fixture\MetadataPropagationWithAsyncProjection']),
            pathToRootCatalog: __DIR__ . "/../../",
            addEventSourcedRepository: false
        );
    }
}
