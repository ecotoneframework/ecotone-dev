<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\EventSourcing\EventSourcingConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Enqueue\Dbal\DbalConnectionFactory;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\Basket\Basket;
use Test\Ecotone\EventSourcing\Fixture\Basket\BasketEventConverter;
use Test\Ecotone\EventSourcing\Fixture\Basket\Command\AddProduct;
use Test\Ecotone\EventSourcing\Fixture\Basket\Command\CreateBasket;
use Test\Ecotone\EventSourcing\Fixture\Snapshots\BasketMediaTypeConverter;
use Test\Ecotone\EventSourcing\Fixture\Snapshots\TicketMediaTypeConverter;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;

/**
 * @internal
 */
final class SnapshotsTest extends EventSourcingMessagingTestCase
{
    public function test_snapshotting_aggregates_called_in_turn(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            containerOrAvailableServices: [new BasketEventConverter(), new BasketMediaTypeConverter(), new TicketEventConverter(), new TicketMediaTypeConverter(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames([ModulePackageList::AMQP_PACKAGE, ModulePackageList::JMS_CONVERTER_PACKAGE])
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Basket',
                    'Test\Ecotone\EventSourcing\Fixture\Snapshots',
                ])
                ->withExtensionObjects([
                    EventSourcingConfiguration::createWithDefaults()
                        ->withSnapshots([Ticket::class, Basket::class], 1),
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );

        $ecotone
            ->sendCommand(new CreateBasket('1000'))
            ->sendCommand(new CreateBasket('1001'))
            ->sendCommand(new AddProduct('1000', 'milk'))
            ->sendCommand(new AddProduct('1001', 'cheese'))
            ->sendCommand(new AddProduct('1000', 'ham'))
            ->sendCommand(new AddProduct('1001', 'cheese'))
            ->sendCommand(new AddProduct('1001', 'milk'))
        ;

        self::assertEquals(
            'milk,ham',
            implode(',', $ecotone->sendQueryWithRouting('basket.getCurrent', metadata: ['aggregate.id' => '1000']))
        );

        self::assertEquals(
            'cheese,cheese,milk',
            implode(',', $ecotone->sendQueryWithRouting('basket.getCurrent', metadata: ['aggregate.id' => '1001']))
        );
    }
}
