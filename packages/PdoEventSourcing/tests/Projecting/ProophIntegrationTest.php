<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting;

use Ecotone\EventSourcing\Projecting\PartitionState\DbalProjectionStateStorageBuilder;
use Ecotone\EventSourcing\Projecting\StreamSource\EventStoreAggregateStreamSourceBuilder;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Event;
use Ecotone\Projecting\Attribute\Projection;
use Ecotone\Projecting\InMemory\InMemoryStreamSourceBuilder;
use Ecotone\Projecting\ProjectionRegistry;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\EventSourcing\Projecting\Fixture\DbalTicketProjection;
use Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket\CreateTicketCommand;
use Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket\TicketAssigned;
use Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket\TicketCreated;
use Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket\TicketEventConverter;
use Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket\TicketUnassigned;
use Test\Ecotone\EventSourcing\Projecting\Fixture\TicketProjection;

/**
 * @internal
 */
class ProophIntegrationTest extends ProjectingTestCase
{
    public function test_it_can_project_events(): void
    {
        $connectionFactory = self::getConnectionFactory();
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            [DbalTicketProjection::class, Ticket::class, TicketEventConverter::class, TicketAssigned::class],
            [$connectionFactory, new DbalTicketProjection($connectionFactory->establishConnection()), new TicketEventConverter()],
            runForProductionEventStore: true
        );

        $ticketsCount = $ecotone->deleteEventStream(Ticket::STREAM_NAME)
            ->deleteProjection(DbalTicketProjection::NAME)
            ->sendCommand(new CreateTicketCommand($ticketId = Uuid::uuid4()->toString()))
            ->sendCommandWithRoutingKey(Ticket::ASSIGN_COMMAND, metadata: ['aggregate.id' => $ticketId])
            ->sendQueryWithRouting('getTicketsCount');

        self::assertSame(1, $ticketsCount);
        self::assertSame('assigned', $ecotone->sendQueryWithRouting('getTicketStatus', $ticketId));

        $ticketsCount = $ecotone->deleteProjection(DbalTicketProjection::NAME)
            ->initializeProjection(DbalTicketProjection::NAME)
            ->sendQueryWithRouting('getTicketsCount');

        self::assertSame(0, $ticketsCount);

        $ticketsCount = $ecotone->triggerProjection(DbalTicketProjection::NAME)
            ->sendQueryWithRouting('getTicketsCount');

        self::assertSame(1, $ticketsCount);
        self::assertSame('assigned', $ecotone->sendQueryWithRouting('getTicketStatus', $ticketId));
    }

    public function test_partitioned_asynchronous_projection(): void
    {
        $projection = new #[Projection(self::NAME, MessageHeaders::EVENT_AGGREGATE_ID), Asynchronous(self::ASYNC_CHANNEL)] class {
            public const NAME = 'projection_with_async';
            public const ASYNC_CHANNEL = 'async_projection';

            public array $projectedEvents = [];
            #[EventHandler]
            public function on(TicketCreated $event): void
            {
                $this->projectedEvents[] = $event;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$projection::class],
            [$projection],
            ServiceConfiguration::createWithDefaults()
                ->addExtensionObject($streamSource = new InMemoryStreamSourceBuilder(partitionField: MessageHeaders::EVENT_AGGREGATE_ID)),
            enableAsynchronousProcessing: [
                SimpleMessageChannelBuilder::createQueueChannel($projection::ASYNC_CHANNEL),
            ],
        );

        $streamSource->append(
            Event::create(new TicketCreated('ticket-1'), [MessageHeaders::EVENT_AGGREGATE_ID => 'ticket-1']),
            Event::create(new TicketCreated('ticket-4'), [MessageHeaders::EVENT_AGGREGATE_ID => 'ticket-4']),
        );
        $ecotone->publishEvent(new TicketCreated('ticket-that-triggers-projection'), [MessageHeaders::EVENT_AGGREGATE_ID => 'ticket-1']);
        self::assertEquals([], $projection->projectedEvents);

        $ecotone->run($projection::ASYNC_CHANNEL, ExecutionPollingMetadata::createWithTestingSetup());

        self::assertEquals([new TicketCreated('ticket-1')], $projection->projectedEvents);

        $ecotone->publishEvent(new TicketCreated('ticket-that-triggers-projection'), [MessageHeaders::EVENT_AGGREGATE_ID => 'ticket-1']);
        $ecotone->run($projection::ASYNC_CHANNEL, ExecutionPollingMetadata::createWithTestingSetup()->withFinishWhenNoMessages(true));

        self::assertEquals([new TicketCreated('ticket-1')], $projection->projectedEvents);
    }

    public function test_it_can_use_user_projection_state(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            [TicketProjection::class, Ticket::class, TicketAssigned::class, TicketEventConverter::class],
            [$projection = new TicketProjection(), $this->getConnectionFactory(), new TicketEventConverter()],
            ServiceConfiguration::createWithDefaults()
                ->addExtensionObject(new EventStoreAggregateStreamSourceBuilder(TicketProjection::NAME, Ticket::class, Ticket::STREAM_NAME))
                ->addExtensionObject(new DbalProjectionStateStorageBuilder([TicketProjection::NAME])),
            runForProductionEventStore: true,
        );

        $ecotone->deleteEventStream(Ticket::STREAM_NAME);
        $projectionRegistry = $ecotone->getGateway(ProjectionRegistry::class);
        $projectionRegistry->get(TicketProjection::NAME)->delete();

        self::assertEquals([], $projection->getProjectedEvents());

        $ecotone->sendCommand(new CreateTicketCommand('ticket-10'));
        $ecotone->sendCommandWithRoutingKey(Ticket::ASSIGN_COMMAND, metadata: ['aggregate.id' => 'ticket-10']);
        $ecotone->sendCommandWithRoutingKey(Ticket::ASSIGN_COMMAND, metadata: ['aggregate.id' => 'ticket-10']);

        self::assertEquals(
            [
                new TicketCreated('ticket-10'),
                new TicketAssigned('ticket-10'),
                new TicketAssigned('ticket-10'),
            ],
            $projection->getProjectedEvents()
        );

        $ecotone->sendCommandWithRoutingKey(Ticket::ASSIGN_COMMAND, metadata: ['aggregate.id' => 'ticket-10']);

        self::assertEquals(
            [
                new TicketCreated('ticket-10'),
                new TicketAssigned('ticket-10'),
                new TicketAssigned('ticket-10'),
            ],
            $projection->getProjectedEvents(),
            'A maximum of ' . TicketProjection::MAX_ASSIGNMENT_COUNT . ' successive assignment on the same ticket should be recorded'
        );

        $ecotone->sendCommandWithRoutingKey(Ticket::UNASSIGN_COMMAND, metadata: ['aggregate.id' => 'ticket-10']);

        self::assertEquals(
            [
                new TicketCreated('ticket-10'),
                new TicketAssigned('ticket-10'),
                new TicketAssigned('ticket-10'),
                new TicketUnassigned('ticket-10'),
            ],
            $projection->getProjectedEvents(),
        );
    }
}
