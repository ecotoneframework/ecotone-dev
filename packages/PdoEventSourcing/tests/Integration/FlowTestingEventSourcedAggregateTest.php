<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Api\Attribute\AggregateType;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Modelling\WithAggregateVersioning;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class FlowTestingEventSourcedAggregateTest extends EventSourcingMessagingTestCase
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

    public function test_an_event_sourced_aggregate_in_a_flow_test_given_a_connection_is_loaded_from_the_database_by_another_flow_test(): void
    {
        $converter = new class () {
            #[Converter]
            public function fromPlaced(FlowTestingOrderPlaced $event): array
            {
                return ['orderId' => $event->orderId, 'product' => $event->product];
            }

            #[Converter]
            public function toPlaced(array $event): FlowTestingOrderPlaced
            {
                return new FlowTestingOrderPlaced($event['orderId'], $event['product']);
            }
        };
        $flowTest = fn () => EcotoneLite::bootstrapFlowTesting(
            [FlowTestingOrder::class, $converter::class],
            [$converter, DbalConnectionFactory::class => self::getConnectionFactory()],
        );

        $flowTest()->sendCommand(new PlaceFlowTestingOrder('o-1', 'coffee'));

        self::assertSame('coffee', $flowTest()->getAggregate(FlowTestingOrder::class, 'o-1')->product);
    }
}

final class PlaceFlowTestingOrder
{
    public function __construct(public string $orderId, public string $product)
    {
    }
}

final class FlowTestingOrderPlaced
{
    public function __construct(public string $orderId, public string $product)
    {
    }
}

#[EventSourcingAggregate]
#[AggregateType('flow_testing_order')]
final class FlowTestingOrder
{
    use WithAggregateVersioning;

    #[Identifier]
    public string $orderId;
    public string $product;

    #[CommandHandler]
    public static function place(PlaceFlowTestingOrder $command): array
    {
        return [new FlowTestingOrderPlaced($command->orderId, $command->product)];
    }

    #[EventSourcingHandler]
    public function applyPlaced(FlowTestingOrderPlaced $event): void
    {
        $this->orderId = $event->orderId;
        $this->product = $event->product;
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
