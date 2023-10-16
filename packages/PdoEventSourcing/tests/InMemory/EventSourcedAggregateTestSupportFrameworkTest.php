<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\InMemory;

use Ecotone\EventSourcing\EventSourcingConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\Configuration\InMemoryRepositoryBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Enqueue\Dbal\DbalConnectionFactory;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\ChangeAssignedPerson;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\CloseTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\AssignedPersonWasChanged;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasClosed;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;
use Test\Ecotone\EventSourcing\Fixture\TicketWithInMemoryAsynchronousEventDrivenProjection\InProgressTicketList;

/**
 * @internal
 */
final class EventSourcedAggregateTestSupportFrameworkTest extends TestCase
{
    public function test_calling_aggregate_and_receiving_events()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapForTesting(
            [Ticket::class],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE]))
                ->withExtensionObjects([
                    InMemoryRepositoryBuilder::createForAllEventSourcedAggregates(),
                ]),
        );

        $this->assertEquals(
            [new TicketWasRegistered('1', 'johny', 'alert')],
            $ecotoneTestSupport->getFlowTestSupport()
                ->sendCommand(new RegisterTicket('1', 'johny', 'alert'))
                ->getRecordedEvents()
        );
    }

    public function test_calling_multiple_commands_on_aggregate_and_receiving_recorded_events()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapForTesting(
            [Ticket::class],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE]))
                ->withExtensionObjects([
                    InMemoryRepositoryBuilder::createForAllEventSourcedAggregates(),
                ]),
        );

        $ticketId = '1';

        $this->assertEquals(
            [new TicketWasRegistered($ticketId, 'johny', 'alert'), new TicketWasClosed($ticketId)],
            $ecotoneTestSupport->getFlowTestSupport()
                ->sendCommand(new RegisterTicket($ticketId, 'johny', 'alert'))
                ->sendCommand(new CloseTicket($ticketId))
                ->getRecordedEvents()
        );
    }

    public function test_calling_multiple_commands_on_aggregate_and_receiving_last_event()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapForTesting(
            [Ticket::class],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE]))
                ->withExtensionObjects([
                    InMemoryRepositoryBuilder::createForAllEventSourcedAggregates(),
                ]),
        );

        $ticketId = '1';

        $this->assertEquals(
            [new TicketWasClosed($ticketId)],
            $ecotoneTestSupport->getFlowTestSupport()
                ->sendCommand(new RegisterTicket($ticketId, 'johny', 'alert'))
                ->discardRecordedMessages()
                ->sendCommand(new CloseTicket($ticketId))
                ->getRecordedEvents()
        );
    }

    public function test_calling_command_on_aggregate_and_receiving_aggregate_instance()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [Ticket::class],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE])),
        );

        $ticketId = '1';

        $ticket = new Ticket();
        $ticket->applyTicketWasRegistered(new TicketWasRegistered($ticketId, 'johny', 'alert'));
        $ticket->setVersion(1);

        $this->assertEquals(
            $ticket,
            $ecotoneTestSupport
                ->sendCommand(new RegisterTicket($ticketId, 'johny', 'alert'))
                ->getAggregate(Ticket::class, $ticketId)
        );
    }

    public function test_providing_initial_state_in_form_of_events()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting([Ticket::class]);

        $ticketId = '1';

        /** Setting up event sourced aggregate initial events */
        $this->assertEquals(
            'Andrew',
            $ecotoneTestSupport
                ->withEventsFor($ticketId, Ticket::class, [
                    new TicketWasRegistered($ticketId, 'Johny', 'alert'),
                    new AssignedPersonWasChanged($ticketId, 'Elvis'),
                ])
                ->sendCommand(new ChangeAssignedPerson($ticketId, 'Andrew'))
                ->getAggregate(Ticket::class, $ticketId)
                ->getAssignedPerson()
        );
    }

    public function test_initial_state_is_not_considered_as_recorded_events()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting([Ticket::class]);

        $ticketId = '1';

        $this->assertEquals(
            [],
            $ecotoneTestSupport
                ->withEventsFor($ticketId, Ticket::class, [
                    new TicketWasRegistered($ticketId, 'Johny', 'alert'),
                    new AssignedPersonWasChanged($ticketId, 'Elvis'),
                ])
                ->getRecordedEvents()
        );
    }

    public function test_providing_initial_state_in_form_of_events_with_event_store()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTestingWithEventStore(
            [Ticket::class, TicketEventConverter::class],
            [new TicketEventConverter(), DbalConnectionFactory::class => EcotoneLiteEventSourcingTest::getConnectionFactory()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::DBAL_PACKAGE]))
        );

        $ticketId = '1';

        /** Setting up event sourced aggregate initial events */
        $this->assertEquals(
            'Andrew',
            $ecotoneTestSupport
                ->withEventsFor($ticketId, Ticket::class, [
                    new TicketWasRegistered($ticketId, 'Johny', 'alert'),
                    new AssignedPersonWasChanged($ticketId, 'Elvis'),
                ])
                ->sendCommand(new ChangeAssignedPerson($ticketId, 'Andrew'))
                ->getAggregate(Ticket::class, $ticketId)
                ->getAssignedPerson()
        );
    }

    public function test_registering_in_memory_event_sourcing_repository()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapForTesting(
            [Ticket::class, TicketEventConverter::class, InProgressTicketList::class],
            [new TicketEventConverter(), new InProgressTicketList()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE]))
                ->withEnvironment('test')
                ->withExtensionObjects([
                    EventSourcingConfiguration::createInMemory(),
                ]),
        );

        $this->assertCount(
            1,
            $ecotoneTestSupport->getFlowTestSupport()
                ->sendCommand(new RegisterTicket('1', 'johny', 'alert'))
                ->discardRecordedMessages()
                ->sendQueryWithRouting('getInProgressTickets')
        );
    }
}
