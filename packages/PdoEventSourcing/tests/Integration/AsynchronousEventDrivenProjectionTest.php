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
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\CloseTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;
use Test\Ecotone\EventSourcing\Fixture\TicketWithAsynchronousEventDrivenProjection\InProgressTicketList;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class AsynchronousEventDrivenProjectionTest extends EventSourcingMessagingTestCase
{
    public function test_building_asynchronous_event_driven_projection(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            containerOrAvailableServices: [new InProgressTicketList($this->getConnection()), new TicketEventConverter(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Ticket',
                    'Test\Ecotone\EventSourcing\Fixture\TicketWithAsynchronousEventDrivenProjection',
                ])
                ->withExtensionObjects([
                    EventSourcingConfiguration::createWithDefaults(),
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );

        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));
        $ecotone->run(InProgressTicketList::PROJECTION_CHANNEL);

        self::assertEquals([['ticket_id' => '123', 'ticket_type' => 'alert']], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        $ecotone->sendCommand(new CloseTicket('123'));

        self::assertEquals([['ticket_id' => '123', 'ticket_type' => 'alert']], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        $ecotone->run(InProgressTicketList::PROJECTION_CHANNEL);

        self::assertEquals([], $ecotone->sendQueryWithRouting('getInProgressTickets'));
    }

    public function test_operations_on_asynchronous_event_driven_projection(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            containerOrAvailableServices: [new InProgressTicketList($this->getConnection()), new TicketEventConverter(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Ticket',
                    'Test\Ecotone\EventSourcing\Fixture\TicketWithAsynchronousEventDrivenProjection',
                ])
                ->withExtensionObjects([
                    EventSourcingConfiguration::createWithDefaults(),
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );

        $ecotone->sendCommand(new RegisterTicket('123', 'Johnny', 'alert'));
        $ecotone->run(InProgressTicketList::PROJECTION_CHANNEL);
        $ecotone->stopProjection(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);
        $ecotone->run(InProgressTicketList::PROJECTION_CHANNEL);
        $ecotone->sendCommand(new RegisterTicket('1234', 'Johnny', 'alert'));

        self::assertEquals([['ticket_id' => '123', 'ticket_type' => 'alert']], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        $ecotone->resetProjection(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);
        $ecotone->run(InProgressTicketList::PROJECTION_CHANNEL);

        self::assertEquals([
            ['ticket_id' => '123', 'ticket_type' => 'alert'],
            ['ticket_id' => '1234', 'ticket_type' => 'alert'],
        ], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        $ecotone->sendCommand(new CloseTicket('123'));
        $ecotone->run(InProgressTicketList::PROJECTION_CHANNEL);

        self::assertEquals([['ticket_id' => '1234', 'ticket_type' => 'alert']], $ecotone->sendQueryWithRouting('getInProgressTickets'));

        $ecotone->deleteProjection(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);
        $ecotone->run(InProgressTicketList::PROJECTION_CHANNEL);

        self::assertFalse(self::tableExists($this->getConnection(), 'in_progress_tickets'));
    }

    public function test_catching_up_events_after_reset_synchronous_event_driven_projection(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            containerOrAvailableServices: [new InProgressTicketList($this->getConnection()), new TicketEventConverter(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Ticket',
                    'Test\Ecotone\EventSourcing\Fixture\TicketWithAsynchronousEventDrivenProjection',
                ])
                ->withExtensionObjects([
                    EventSourcingConfiguration::createWithDefaults(),
                    ProjectionRunningConfiguration::createEventDriven(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION)
                        ->withTestingSetup()
                        ->withOption(ProophProjectionRunningOption::OPTION_LOAD_COUNT, 2),
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

        $ecotone->run(InProgressTicketList::PROJECTION_CHANNEL);
        $ecotone->resetProjection(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION);
        $ecotone->run(InProgressTicketList::PROJECTION_CHANNEL);

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
