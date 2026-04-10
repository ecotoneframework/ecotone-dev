<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\Partitioned;

use Ecotone\EventSourcing\Attribute\FromAggregateStream;
use Ecotone\EventSourcing\Attribute\FromStream;
use Ecotone\EventSourcing\Attribute\ProjectionState;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Projecting\Attribute\Partitioned;
use Ecotone\Projecting\Attribute\ProjectionV2;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\EventSourcing\Fixture\Basket\Basket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\CloseTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasClosed;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;
use Test\Ecotone\EventSourcing\Fixture\TicketProjectionState\CounterState;
use Test\Ecotone\EventSourcing\Fixture\TicketProjectionState\PartitionedCounterStateGateway;
use Test\Ecotone\EventSourcing\Fixture\TicketProjectionState\PartitionedCounterStateWithStreamGateway;
use Test\Ecotone\EventSourcing\Fixture\TicketProjectionState\StateAndEventConverter;
use Test\Ecotone\EventSourcing\Projecting\ProjectingTestCase;

/**
 * licence Enterprise
 * @internal
 */
final class ProjectionStateGatewayTest extends ProjectingTestCase
{
    public function test_fetching_partitioned_projection_state_via_gateway_with_aggregate_id(): void
    {
        $projection = $this->createCounterProjection();

        $ecotone = $this->bootstrapEcotone(
            [$projection::class, PartitionedCounterStateGateway::class, StateAndEventConverter::class],
            [$projection, new StateAndEventConverter()]
        );

        $ecotone->initializeProjection($projection::NAME);
        $ecotone->deleteProjection($projection::NAME);

        $ecotone->sendCommand(new RegisterTicket('ticket-1', 'Johnny', 'alert'));
        $ecotone->sendCommand(new RegisterTicket('ticket-2', 'Marcus', 'info'));
        $ecotone->sendCommand(new CloseTicket('ticket-1'));

        $gateway = $ecotone->getGateway(PartitionedCounterStateGateway::class);

        self::assertEquals(
            new CounterState(ticketCount: 1, closedTicketCount: 1),
            $gateway->fetchStateForPartition('ticket-1')
        );
        self::assertEquals(
            new CounterState(ticketCount: 1, closedTicketCount: 0),
            $gateway->fetchStateForPartition('ticket-2')
        );
    }

    public function test_multiple_streams_without_from_aggregate_stream_on_gateway_throws_exception(): void
    {
        $projection = new #[
            ProjectionV2('ticket_counter_partitioned'),
            Partitioned,
            FromStream(stream: Ticket::class, aggregateType: Ticket::class),
            FromStream(stream: Basket::class, aggregateType: Basket::class),
        ] class () {
            public const NAME = 'ticket_counter_partitioned';

            #[EventHandler]
            public function onTicketRegistered(TicketWasRegistered $event, #[ProjectionState] array $state = []): array
            {
                return $state;
            }
        };

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('multiple streams');

        $this->bootstrapEcotone(
            [$projection::class, PartitionedCounterStateGateway::class, StateAndEventConverter::class],
            [$projection, new StateAndEventConverter()]
        );
    }

    public function test_from_aggregate_stream_on_gateway_method_disambiguates_multiple_streams(): void
    {
        $projection = new #[
            ProjectionV2('ticket_counter_multi_stream'),
            Partitioned,
            FromAggregateStream(Ticket::class),
            FromAggregateStream(Basket::class),
        ] class () {
            public const NAME = 'ticket_counter_multi_stream';

            #[EventHandler]
            public function onTicketRegistered(TicketWasRegistered $event, #[ProjectionState] array $state = []): array
            {
                $state['ticketCount'] = ($state['ticketCount'] ?? 0) + 1;
                $state['closedTicketCount'] = $state['closedTicketCount'] ?? 0;
                return $state;
            }

            #[EventHandler]
            public function onTicketClosed(TicketWasClosed $event, #[ProjectionState] array $state = []): array
            {
                $state['closedTicketCount'] = ($state['closedTicketCount'] ?? 0) + 1;
                return $state;
            }
        };

        $ecotone = $this->bootstrapEcotone(
            [$projection::class, PartitionedCounterStateWithStreamGateway::class, StateAndEventConverter::class],
            [$projection, new StateAndEventConverter()]
        );

        $ecotone->initializeProjection($projection::NAME);
        $ecotone->deleteProjection($projection::NAME);

        $ecotone->sendCommand(new RegisterTicket('ticket-1', 'Johnny', 'alert'));
        $ecotone->sendCommand(new CloseTicket('ticket-1'));

        $gateway = $ecotone->getGateway(PartitionedCounterStateWithStreamGateway::class);

        self::assertEquals(
            new CounterState(ticketCount: 1, closedTicketCount: 1),
            $gateway->fetchStateForPartition('ticket-1')
        );
    }

    private function createCounterProjection(): object
    {
        return new #[ProjectionV2(self::NAME), Partitioned, FromStream(stream: Ticket::class, aggregateType: Ticket::class)] class () {
            public const NAME = 'ticket_counter_partitioned';

            #[EventHandler]
            public function onTicketRegistered(TicketWasRegistered $event, #[ProjectionState] array $state = []): array
            {
                $state['ticketCount'] = ($state['ticketCount'] ?? 0) + 1;
                $state['closedTicketCount'] = $state['closedTicketCount'] ?? 0;
                return $state;
            }

            #[EventHandler]
            public function onTicketClosed(TicketWasClosed $event, #[ProjectionState] array $state = []): array
            {
                $state['closedTicketCount'] = ($state['closedTicketCount'] ?? 0) + 1;
                return $state;
            }
        };
    }

    private function bootstrapEcotone(array $classesToResolve, array $services): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: array_merge($classesToResolve, [Ticket::class, TicketEventConverter::class]),
            containerOrAvailableServices: array_merge($services, [new TicketEventConverter(), self::getConnectionFactory()]),
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([
                    ModulePackageList::DBAL_PACKAGE,
                    ModulePackageList::EVENT_SOURCING_PACKAGE,
                    ModulePackageList::ASYNCHRONOUS_PACKAGE,
                ])),
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
