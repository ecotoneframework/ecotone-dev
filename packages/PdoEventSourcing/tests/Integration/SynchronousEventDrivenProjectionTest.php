<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\EventSourcing\EventSourcingConfiguration;
use Ecotone\EventSourcing\ProjectionRunningConfiguration;
use Ecotone\EventSourcing\Prooph\ProophProjectionRunningOption;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Enqueue\Dbal\DbalConnectionFactory;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\Basket\Basket;
use Test\Ecotone\EventSourcing\Fixture\ServiceEventHandler\TicketNotificationEventHandler;
use Test\Ecotone\EventSourcing\Fixture\Snapshots\BasketMediaTypeConverter;
use Test\Ecotone\EventSourcing\Fixture\Snapshots\TicketMediaTypeConverter;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\ChangeAssignedPerson;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\CloseTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;
use Test\Ecotone\EventSourcing\Fixture\TicketWithSynchronousEventDrivenProjection\InProgressTicketList;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class SynchronousEventDrivenProjectionTest extends EventSourcingMessagingTestCase
{
    public function test_building_synchronous_event_driven_projection(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            containerOrAvailableServices: [new InProgressTicketList($this->getConnection()), new TicketEventConverter(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE]))
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Ticket',
                    'Test\Ecotone\EventSourcing\Fixture\TicketWithSynchronousEventDrivenProjection',
                ])
            ->withExtensionObjects([
                EventSourcingConfiguration::createWithDefaults(),
            ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );

        $ecotone->initializeProjection(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);

        self::assertEquals([], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));

        self::assertEquals([['ticket_id' => '123', 'ticket_type' => 'alert']], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        $ecotone->deleteProjection(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);

        $ecotone->sendCommand(new CloseTicket('123'));
        $ecotone->sendCommand(new RegisterTicket('124', 'Johnny', 'info'));

        self::assertEquals([['ticket_id' => '124', 'ticket_type' => 'info']], $ecotone->sendQueryWithRouting('getInProgressTickets'));
    }

    public function test_synchronous_event_driven_projection_should_be_called_before_standard_event_handlers(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            containerOrAvailableServices: [
                new InProgressTicketList($this->getConnection()),
                new TicketEventConverter(), DbalConnectionFactory::class => $this->getConnectionFactory(),
                new TicketNotificationEventHandler(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE]))
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Ticket',
                    'Test\Ecotone\EventSourcing\Fixture\TicketWithSynchronousEventDrivenProjection',
                    'Test\Ecotone\EventSourcing\Fixture\ServiceEventHandler',
                ])
                ->withExtensionObjects([
                    EventSourcingConfiguration::createWithDefaults(),
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );

        $ecotone->initializeProjection(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);

        self::assertEquals([], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));

        self::assertEquals([[['ticket_id' => '123', 'ticket_type' => 'alert']]], $ecotone->sendQueryWithRouting('getNotifications'));
    }

    public function test_operations_on_synchronous_event_driven_projection(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            containerOrAvailableServices: [new InProgressTicketList($this->getConnection()), new TicketEventConverter(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE]))
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Ticket',
                    'Test\Ecotone\EventSourcing\Fixture\TicketWithSynchronousEventDrivenProjection',
                ])
            ->withExtensionObjects([
                EventSourcingConfiguration::createWithDefaults(),
            ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );

        $ecotone->sendCommand(new RegisterTicket('123', 'Marcus', 'alert'));
        $ecotone->stopProjection(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);
        $ecotone->sendCommand(new RegisterTicket('124', 'Andrew', 'alert'));

        self::assertEquals([
            ['ticket_id' => '123', 'ticket_type' => 'alert'],
            ['ticket_id' => '124', 'ticket_type' => 'alert'],
        ], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        $ecotone->resetProjection(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);

        self::assertEquals([
            ['ticket_id' => '123', 'ticket_type' => 'alert'],
            ['ticket_id' => '124', 'ticket_type' => 'alert'],
        ], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        $ecotone->deleteProjection(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);
        self::assertFalse(self::tableExists($this->getConnection(), 'in_progress_tickets'));
    }

    public function test_building_projection_from_event_sourced_when_snapshots_are_enabled(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            containerOrAvailableServices: [new InProgressTicketList($this->getConnection()), new TicketEventConverter(), new TicketMediaTypeConverter(), new BasketMediaTypeConverter(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Ticket',
                    'Test\Ecotone\EventSourcing\Fixture\TicketWithSynchronousEventDrivenProjection',
                ])
                ->withExtensionObjects([
                    EventSourcingConfiguration::createWithDefaults()
                        ->withSnapshots([Ticket::class, Basket::class], 1),
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );

        $ecotone->initializeProjection(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);

        self::assertEquals([], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));
        $ecotone->sendCommand(new ChangeAssignedPerson('123', 'Franco'));

        self::assertEquals([['ticket_id' => '123', 'ticket_type' => 'alert']], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        $ecotone->sendCommand(new CloseTicket('123'));
        $ecotone->sendCommand(new RegisterTicket('124', 'Johnny', 'info'));

        self::assertEquals([['ticket_id' => '124', 'ticket_type' => 'info']], $ecotone->sendQueryWithRouting('getInProgressTickets'));
    }

    public function test_building_synchronous_event_driven_projection_using_in_memory_event_store(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            containerOrAvailableServices: [new InProgressTicketList($this->getConnection()), new TicketEventConverter(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE]))
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Ticket',
                    'Test\Ecotone\EventSourcing\Fixture\TicketWithSynchronousEventDrivenProjection',
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );

        $ecotone->initializeProjection(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);

        self::assertEquals([], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));

        self::assertEquals([['ticket_id' => '123', 'ticket_type' => 'alert']], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        $ecotone->sendCommand(new CloseTicket('123'));
        $ecotone->sendCommand(new RegisterTicket('124', 'Johnny', 'info'));

        self::assertEquals([['ticket_id' => '124', 'ticket_type' => 'info']], $ecotone->sendQueryWithRouting('getInProgressTickets'));
    }

    public function test_catching_up_events_after_reset_synchronous_event_driven_projection(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            containerOrAvailableServices: [new InProgressTicketList($this->getConnection()), new TicketEventConverter(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE]))
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Ticket',
                    'Test\Ecotone\EventSourcing\Fixture\TicketWithSynchronousEventDrivenProjection',
                ])
                ->withExtensionObjects([
                    EventSourcingConfiguration::createWithDefaults(),
                    ProjectionRunningConfiguration::createEventDriven(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION)
                        ->withTestingSetup()
                        ->withOption(ProophProjectionRunningOption::OPTION_LOAD_COUNT, 100),
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );

        $ecotone->sendCommand(new RegisterTicket('1', 'Marcus', 'alert'));
        $ecotone->sendCommand(new RegisterTicket('2', 'Andrew', 'alert'));
        $ecotone->sendCommand(new RegisterTicket('3', 'Andrew', 'alert'));
        $ecotone->sendCommand(new RegisterTicket('4', 'Thomas', 'info'));
        $ecotone->sendCommand(new RegisterTicket('5', 'Peter', 'info'));
        $ecotone->sendCommand(new RegisterTicket('6', 'Maik', 'info'));
        $ecotone->sendCommand(new RegisterTicket('7', 'Jack', 'warning'));

        $ecotone->resetProjection(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);

        self::assertEquals([
            ['ticket_id' => '1', 'ticket_type' => 'alert'],
            ['ticket_id' => '2', 'ticket_type' => 'alert'],
            ['ticket_id' => '3', 'ticket_type' => 'alert'],
            ['ticket_id' => '4', 'ticket_type' => 'info'],
            ['ticket_id' => '5', 'ticket_type' => 'info'],
            ['ticket_id' => '6', 'ticket_type' => 'info'],
            ['ticket_id' => '7', 'ticket_type' => 'warning'],
        ], $ecotone->sendQueryWithRouting('getInProgressTickets'));
    }
}
