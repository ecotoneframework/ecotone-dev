<?php

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates\AddItemToBasket;
use Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates\Basket;
use Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates\BasketCreated;
use Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates\Converters;
use Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates\InventoryStockIncreased;
use Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates\ItemInventory;
use Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates\ItemInventoryCreated;
use Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates\ItemReserved;
use Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates\ItemWasAddedToBasket;

class StatefulEventSourcedWorkflowWithMultipleAggregatesTest extends EventSourcingMessagingTestCase
{
    public function test_stateful_event_sourced_workflow_with_multiple_aggregates(): void
    {
        $ecotone = $this->bootstrapEcotone();

        $ecotone->withEventsFor(
            'basket-1',
            Basket::class,
            [
                new BasketCreated('basket-1'),
            ]
        );

        $ecotone->withEventsFor(
            'item-1',
            ItemInventory::class,
            [
                new ItemInventoryCreated('item-1'),
                new InventoryStockIncreased('item-1', 5),
                new InventoryStockIncreased('item-1', 10),
                new ItemReserved('item-1', 2),
                new InventoryStockIncreased('item-1', 3),
            ]
        );

        $ecotone->sendCommand(command: new AddItemToBasket('basket-1', 'item-1', 4));

        self::assertEquals(
            [
                new ItemWasAddedToBasket('basket-1', 'item-1', 4),
                new ItemReserved('item-1', 4),
            ],
            $ecotone->getRecordedEvents(),
        );
    }

    private function bootstrapEcotone(): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [
                Basket::class,
                ItemInventory::class,
            ],
            containerOrAvailableServices: [
                new Converters(),
                self::getConnectionFactory(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::DBAL_PACKAGE]))
                ->withNamespaces(['Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates']),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true,
        );
    }
}
