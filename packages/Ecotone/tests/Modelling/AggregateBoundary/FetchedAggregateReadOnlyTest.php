<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\AggregateBoundary;

use Ecotone\Api\Attribute\AggregateType;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Attribute\Fetch;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\WithoutDatabaseTransaction;
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Modelling\WithAggregateVersioning;
use Ecotone\Modelling\WithEvents;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;

/**
 * licence Enterprise
 * @internal
 */
final class FetchedAggregateReadOnlyTest extends TestCase
{
    public function test_a_fetched_event_sourced_aggregate_that_recorded_events_in_a_dcb_handler_is_rejected_and_nothing_is_appended(): void
    {
        $ecotone = $this->bootstrapWithDcb([TicketDesk::class]);
        $ecotone->sendCommandWithRouting('ticket.open', 't-1');

        try {
            $ecotone->sendCommand(new EscalateTicket('t-1', 'VIP', closeTheFetchedTicket: true));
            $this->fail('Expected the read-only violation to be reported');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('fetched aggregates are read-only', $exception->getMessage());
            $this->assertStringContainsString(RecordingTicket::class, $exception->getMessage());
            $this->assertStringContainsString(TicketClosed::class, $exception->getMessage());
        }

        $this->assertSame(0, count($ecotone->getServiceFromContainer(EventStore::class)->loadByCriteria(EventCriteria::tag('queue', 'VIP'))->events));
    }

    public function test_a_fetched_event_sourced_aggregate_that_recorded_nothing_lets_the_dcb_handler_append(): void
    {
        $ecotone = $this->bootstrapWithDcb([TicketDesk::class]);
        $ecotone->sendCommandWithRouting('ticket.open', 't-1');

        $ecotone->sendCommand(new EscalateTicket('t-1', 'VIP'));

        $this->assertSame(1, count($ecotone->getServiceFromContainer(EventStore::class)->loadByCriteria(EventCriteria::tag('queue', 'VIP'))->events));
    }

    public function test_a_fetched_aggregate_that_recorded_events_in_a_handler_outside_the_boundary_behaves_as_today(): void
    {
        $ecotone = $this->bootstrapWithDcb([TicketPeek::class]);
        $ecotone->sendCommandWithRouting('ticket.open', 't-1');

        $this->assertSame('peeked', $ecotone->getCommandBus()->send(new PeekTicket('t-1')));
    }

    public function test_an_aggregate_command_handler_marked_without_database_transaction_is_rejected_at_bootstrap_when_dcb_is_enabled(): void
    {
        try {
            $this->bootstrapWithDcb([TransactionlessTicket::class]);
            $this->fail('Expected a ConfigurationException');
        } catch (ConfigurationException $exception) {
            $this->assertStringContainsString(TransactionlessTicket::class . '::reopen', $exception->getMessage());
            $this->assertStringContainsString('#[WithoutDatabaseTransaction]', $exception->getMessage());
        }
    }

    public function test_an_aggregate_command_handler_marked_without_database_transaction_boots_when_dcb_is_disabled(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(classesToResolve: [TransactionlessTicket::class, TicketOpened::class]);

        $ecotone->sendCommandWithRouting('transactionlessTicket.open', 't-1');

        $this->assertSame(1, $ecotone->getAggregate(TransactionlessTicket::class, 't-1')->getVersion());
    }

    /**
     * @param class-string[] $services
     */
    private function bootstrapWithDcb(array $services): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [RecordingTicket::class, TicketOpened::class, TicketClosed::class, TicketQueue::class, TicketEscalated::class, ...$services],
            containerOrAvailableServices: array_map(static fn (string $service): object => new $service(), array_filter($services, static fn (string $class): bool => ! str_contains($class, 'TransactionlessTicket'))),
            configuration: ServiceConfiguration::createWithDefaults()->withExtensionObjects([DynamicConsistencyBoundaryConfiguration::createWithDefaults()]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}

#[EventSourcingAggregate(withInternalEventRecorder: true)]
#[AggregateType('RecordingTicket')]
final class RecordingTicket
{
    use WithAggregateVersioning;
    use WithEvents;

    #[Identifier] private string $ticketId;

    #[CommandHandler('ticket.open')]
    public static function open(string $ticketId): self
    {
        $ticket = new self();
        $ticket->recordThat(new TicketOpened($ticketId));

        return $ticket;
    }

    public function close(): void
    {
        $this->recordThat(new TicketClosed($this->ticketId));
    }

    #[EventSourcingHandler]
    public function applyOpened(TicketOpened $event): void
    {
        $this->ticketId = $event->ticketId;
    }
}

final readonly class TicketOpened
{
    public function __construct(public string $ticketId)
    {
    }
}

final readonly class TicketClosed
{
    public function __construct(public string $ticketId)
    {
    }
}

#[DecisionModel]
final class TicketQueue
{
    #[EventSourcingHandler]
    public function whenEscalated(TicketEscalated $event): void
    {
    }
}

final readonly class TicketEscalated
{
    public function __construct(public string $ticketId, #[EventTag('queue')] public string $queueId)
    {
    }
}

final readonly class EscalateTicket
{
    public function __construct(public string $ticketId, public string $queueId, public bool $closeTheFetchedTicket = false)
    {
    }
}

final readonly class PeekTicket
{
    public function __construct(public string $ticketId)
    {
    }
}

final class TicketDesk
{
    #[CommandHandler]
    public function escalate(EscalateTicket $command, #[Fetch('payload.ticketId')] RecordingTicket $ticket, TicketQueue $queue): array
    {
        if ($command->closeTheFetchedTicket) {
            $ticket->close();
        }

        return [new TicketEscalated($command->ticketId, $command->queueId)];
    }
}

final class TicketPeek
{
    #[CommandHandler]
    public function peek(PeekTicket $command, #[Fetch('payload.ticketId')] RecordingTicket $ticket): string
    {
        $ticket->close();

        return 'peeked';
    }
}

#[EventSourcingAggregate]
#[AggregateType('TransactionlessTicket')]
final class TransactionlessTicket
{
    use WithAggregateVersioning;

    #[Identifier] private string $ticketId;

    #[CommandHandler('transactionlessTicket.open')]
    public static function open(string $ticketId): array
    {
        return [new TicketOpened($ticketId)];
    }

    #[CommandHandler('transactionlessTicket.reopen')]
    #[WithoutDatabaseTransaction]
    public function reopen(): array
    {
        return [new TicketOpened($this->ticketId)];
    }

    #[EventSourcingHandler]
    public function applyOpened(TicketOpened $event): void
    {
        $this->ticketId = $event->ticketId;
    }
}
