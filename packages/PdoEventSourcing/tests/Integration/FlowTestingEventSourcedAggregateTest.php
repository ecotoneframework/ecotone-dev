<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Modelling\WithAggregateVersioning;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class FlowTestingEventSourcedAggregateTest extends TestCase
{
    public function test_an_event_sourced_aggregate_in_a_flow_test_without_a_connection_keeps_its_events_as_objects(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting([FlowTestingTicket::class]);

        $ecotone
            ->sendCommand(new OpenFlowTestingTicket('t-1', new ExternalFlowTestingTicketReference('JIRA-1')))
            ->sendCommand(new ReassignFlowTestingTicket('t-1', new ExternalFlowTestingTicketReference('JIRA-2')));

        self::assertEquals(
            [
                new FlowTestingTicketOpened('t-1', new ExternalFlowTestingTicketReference('JIRA-1')),
                new FlowTestingTicketReassigned('t-1', new ExternalFlowTestingTicketReference('JIRA-2')),
            ],
            $ecotone->popRecordedEvents(),
        );
        self::assertEquals(new ExternalFlowTestingTicketReference('JIRA-2'), $ecotone->getAggregate(FlowTestingTicket::class, 't-1')->reference);
    }
}

interface FlowTestingTicketReference
{
}

final class ExternalFlowTestingTicketReference implements FlowTestingTicketReference
{
    public function __construct(public string $key)
    {
    }
}

final class OpenFlowTestingTicket
{
    public function __construct(public string $ticketId, public FlowTestingTicketReference $reference)
    {
    }
}

final class ReassignFlowTestingTicket
{
    public function __construct(public string $ticketId, public FlowTestingTicketReference $reference)
    {
    }
}

final class FlowTestingTicketOpened
{
    public function __construct(public string $ticketId, public FlowTestingTicketReference $reference)
    {
    }
}

final class FlowTestingTicketReassigned
{
    public function __construct(public string $ticketId, public FlowTestingTicketReference $reference)
    {
    }
}

#[EventSourcingAggregate]
final class FlowTestingTicket
{
    use WithAggregateVersioning;

    #[Identifier]
    public string $ticketId;
    public FlowTestingTicketReference $reference;

    #[CommandHandler]
    public static function open(OpenFlowTestingTicket $command): array
    {
        return [new FlowTestingTicketOpened($command->ticketId, $command->reference)];
    }

    #[CommandHandler]
    public function reassign(ReassignFlowTestingTicket $command): array
    {
        return [new FlowTestingTicketReassigned($command->ticketId, $command->reference)];
    }

    #[EventSourcingHandler]
    public function applyOpened(FlowTestingTicketOpened $event): void
    {
        $this->ticketId = $event->ticketId;
        $this->reference = $event->reference;
    }

    #[EventSourcingHandler]
    public function applyReassigned(FlowTestingTicketReassigned $event): void
    {
        $this->reference = $event->reference;
    }
}
