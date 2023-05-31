<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Lite\EcotoneLite;
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
    public function test_metadata_propagation_with_async_projection(): void
    {
        /** @var DbalConnectionFactory $connectionFactory */
        $connectionFactory = $this->getConnectionFactory();
        $connection = $connectionFactory->createContext()->getDbalConnection();
        $schemaManager = $connection->createSchemaManager();
        if ($schemaManager->tablesExist(names: OrderProjection::TABLE)) {
            $schemaManager->dropTable(name: OrderProjection::TABLE);
        }

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [new OrderProjection($connection), new OrderEventsConverter(), DbalConnectionFactory::class => $connectionFactory],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withNamespaces(['Test\Ecotone\EventSourcing\Fixture\MetadataPropagationWithAsyncProjection']),
            pathToRootCatalog: __DIR__ . "/../../",
            addEventSourcedRepository: false
        );

        $ecotoneLite->sendCommandWithRoutingKey(routingKey: 'order.create', command: 1, metadata: ['foo' => 'bar']);
        $ecotoneLite->sendCommandWithRoutingKey(routingKey: 'order.create', command: 2);
        $ecotoneLite->sendCommandWithRoutingKey(routingKey: 'order.create', command: 3, metadata: ['foo' => 'baz']);

        $ecotoneLite->run(name: OrderProjection::CHANNEL, executionPollingMetadata: ExecutionPollingMetadata::createWithTestingSetup()->withHandledMessageLimit(2));

        self::assertEquals(expected: 4, actual: $ecotoneLite->sendQueryWithRouting('foo_orders.count'));
    }
}
