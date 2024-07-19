<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Dbal\Configuration\DbalConfiguration;
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
use Test\Ecotone\EventSourcing\Fixture\Basket\Event\BasketWasCreated;
use Test\Ecotone\EventSourcing\Fixture\Snapshots\BasketMediaTypeConverter;
use Test\Ecotone\EventSourcing\Fixture\Snapshots\TicketMediaTypeConverter;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\ChangeAssignedPerson;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\CloseTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class SnapshotsTest extends EventSourcingMessagingTestCase
{
    public function test_snapshotting_aggregates_called_in_turn(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [Basket::class], // fixme should not be required when aggregate class is in namespace used with `withNamespaces` method
            containerOrAvailableServices: [new BasketEventConverter(), new BasketMediaTypeConverter(), new TicketEventConverter(), new TicketMediaTypeConverter(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Basket',
                    'Test\Ecotone\EventSourcing\Fixture\Ticket',
                    'Test\Ecotone\EventSourcing\Fixture\Snapshots',
                ])
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()
                        ->withDocumentStore(),
                    EventSourcingConfiguration::createWithDefaults()
                        ->withSnapshotsFor(Ticket::class, 1)
                        ->withSnapshotsFor(Basket::class, 3),
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );

        $ecotone
            ->withEventsFor(
                '1000',
                Basket::class,
                [
                    new BasketWasCreated('1000'),
                ]
            )
            ->sendCommand(new CreateBasket('1001'))
            ->sendCommand(new AddProduct('1000', 'milk'))
            ->sendCommand(new AddProduct('1001', 'cheese'))
            ->sendCommand(new AddProduct('1000', 'ham'))
            ->sendCommand(new AddProduct('1001', 'cheese'))
            ->sendCommand(new AddProduct('1001', 'milk'))
            ->sendCommand(new RegisterTicket('2000', 'Peter', 'issue'))
            ->sendCommand(new ChangeAssignedPerson('2000', 'Lucas'))
            ->sendCommand(new ChangeAssignedPerson('2000', 'Bob'))
            ->sendCommand(new CloseTicket('2000'))
        ;

        self::assertEquals(
            'milk,ham',
            implode(',', $ecotone->sendQueryWithRouting('basket.getCurrent', metadata: ['aggregate.id' => '1000']))
        );

        self::assertEquals(
            'cheese,cheese,milk',
            implode(',', $ecotone->sendQueryWithRouting('basket.getCurrent', metadata: ['aggregate.id' => '1001']))
        );

        self::assertEquals(
            'Bob',
            $ecotone->sendQueryWithRouting('ticket.getAssignedPerson', metadata: ['aggregate.id' => '2000'])
        );

        self::assertTrue($ecotone->sendQueryWithRouting('ticket.isClosed', metadata: ['aggregate.id' => '2000']));
    }
}
