<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\InMemory;

use Ecotone\Lite\EcotoneLite;
use Enqueue\Dbal\DbalConnectionFactory;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
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
/**
 * licence Apache-2.0
 * @internal
 */
final class EventSourcedAggregateTestSupportFrameworkTest extends TestCase
{
    public function test_calling_aggregate_and_receiving_events()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [Ticket::class]
        );

        $ticketId = Uuid::uuid4()->toString();
        $this->assertEquals(
            [new TicketWasRegistered($ticketId, 'johny', 'alert')],
            $ecotoneTestSupport
                ->sendCommand(new RegisterTicket($ticketId, 'johny', 'alert'))
                ->getRecordedEvents()
        );
    }

    public function test_calling_multiple_commands_on_aggregate_and_receiving_recorded_events()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [Ticket::class],
        );

        $ticketId = Uuid::uuid4()->toString();
        $this->assertEquals(
            [new TicketWasRegistered($ticketId, 'johny', 'alert'), new TicketWasClosed($ticketId)],
            $ecotoneTestSupport
                ->sendCommand(new RegisterTicket($ticketId, 'johny', 'alert'))
                ->sendCommand(new CloseTicket($ticketId))
                ->getRecordedEvents()
        );
    }

    public function test_calling_multiple_commands_on_aggregate_and_receiving_last_event()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [Ticket::class]
        );

        $ticketId = Uuid::uuid4()->toString();

        $this->assertEquals(
            [new TicketWasClosed($ticketId)],
            $ecotoneTestSupport
                ->sendCommand(new RegisterTicket($ticketId, 'johny', 'alert'))
                ->discardRecordedMessages()
                ->sendCommand(new CloseTicket($ticketId))
                ->getRecordedEvents()
        );
    }

    public function test_calling_command_on_aggregate_and_receiving_aggregate_instance()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [Ticket::class]
        );

        $ticketId = Uuid::uuid4()->toString();

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

        $ticketId = Uuid::uuid4()->toString();

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

        $ticketId = Uuid::uuid4()->toString();

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

    public function test_initial_state_is_split_into_two()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting([Ticket::class]);

        $ticketId = Uuid::uuid4()->toString();

        $this->assertEquals(
            [],
            $ecotoneTestSupport
                ->withEventsFor($ticketId, Ticket::class, [
                    new TicketWasRegistered($ticketId, 'Johny', 'alert'),
                ])
                ->withEventsFor($ticketId, Ticket::class, [
                    new AssignedPersonWasChanged($ticketId, 'Elvis'),
                ], 1)
                ->getRecordedEvents()
        );
    }

    public function test_providing_initial_state_in_form_of_events_with_event_store()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTestingWithEventStore(
            [Ticket::class, TicketEventConverter::class],
            [new TicketEventConverter(), DbalConnectionFactory::class => EcotoneLiteEventSourcingTest::getConnectionFactory()],
            runForProductionEventStore: true,
        );

        $ticketId = Uuid::uuid4()->toString();

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
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTestingWithEventStore(
            [Ticket::class, TicketEventConverter::class, InProgressTicketList::class],
            [new TicketEventConverter(), new InProgressTicketList()],
        );

        $this->assertCount(
            1,
            $ecotoneTestSupport
                ->sendCommand(new RegisterTicket('1', 'johny', 'alert'))
                ->discardRecordedMessages()
                ->sendQueryWithRouting('getInProgressTickets')
        );
    }
}
