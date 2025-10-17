<?php

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates\AggregatesWithMetadataMapping;
use Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates\AggregatesWithoutMetadataMapping;
use Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates\Common\AddItemToBasket;
use Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates\Common\BasketCreated;
use Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates\Common\Converters;
use Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates\Common\InventoryStockIncreased;
use Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates\Common\ItemInventoryCreated;
use Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates\Common\ItemReserved;
use Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates\Common\ItemWasAddedToBasket;

/**
 * @internal
 */
class StatefulEventSourcedWorkflowWithMultipleAggregatesTest extends EventSourcingMessagingTestCase
{
    public function test_stateful_event_sourced_workflow_with_multiple_aggregates_without_metadata_mapping(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [
                AggregatesWithoutMetadataMapping\Basket::class,
                AggregatesWithoutMetadataMapping\ItemInventory::class,
            ],
            containerOrAvailableServices: [
                new Converters(),
                self::getConnectionFactory(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
              ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
              ->withNamespaces(
                  [
                      'Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates\Common',
                      'Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates\AggregatesWithoutMetadataMapping',
                  ]
              ),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true,
            enableAsynchronousProcessing: [SimpleMessageChannelBuilder::createQueueChannel('itemInventory')],
        );

        $ecotone->withEventsFor(
            'basket-1',
            AggregatesWithoutMetadataMapping\Basket::class,
            [
                new BasketCreated('basket-1'),
            ]
        );

        $ecotone->withEventsFor(
            'item-1',
            AggregatesWithoutMetadataMapping\ItemInventory::class,
            [
                new ItemInventoryCreated('item-1'),
                new InventoryStockIncreased('item-1', 5),
                new InventoryStockIncreased('item-1', 10),
                new ItemReserved('item-1', 2),
                new InventoryStockIncreased('item-1', 3),
            ]
        );

        $ecotone
            ->sendCommand(command: new AddItemToBasket('basket-1', 'item-1', 4))
            ->run('itemInventory')
        ;

        self::assertEquals(
            [
                new ItemWasAddedToBasket('basket-1', 'item-1', 4),
                new ItemReserved('item-1', 4),
            ],
            $ecotone->getRecordedEvents(),
        );
    }

    public function test_stateful_event_sourced_workflow_with_multiple_aggregates_with_metadata_mapping(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [
                AggregatesWithMetadataMapping\Basket::class,
                AggregatesWithMetadataMapping\ItemInventory::class,
            ],
            containerOrAvailableServices: [
                new Converters(),
                self::getConnectionFactory(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates\Common',
                    'Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates\AggregatesWithMetadataMapping',
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true,
            enableAsynchronousProcessing: [SimpleMessageChannelBuilder::createQueueChannel('itemInventory')],
        );

        $ecotone->withEventsFor(
            'basket-1',
            AggregatesWithMetadataMapping\Basket::class,
            [
                new BasketCreated('basket-1'),
            ]
        );

        $ecotone->withEventsFor(
            'item-1',
            AggregatesWithMetadataMapping\ItemInventory::class,
            [
                new ItemInventoryCreated('item-1'),
                new InventoryStockIncreased('item-1', 5),
                new InventoryStockIncreased('item-1', 10),
                new ItemReserved('item-1', 2),
                new InventoryStockIncreased('item-1', 3),
            ]
        );

        $ecotone
            ->sendCommand(command: new AddItemToBasket('basket-1', 'item-1', 4))
            ->run('itemInventory')
        ;

        self::assertEquals(
            [
                new ItemWasAddedToBasket('basket-1', 'item-1', 4),
                new ItemReserved('item-1', 4),
            ],
            $ecotone->getRecordedEvents(),
        );
    }
}
