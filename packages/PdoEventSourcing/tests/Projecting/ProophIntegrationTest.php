<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting;

use Ecotone\EventSourcing\Attribute\ProjectionInitialization;
use Ecotone\EventSourcing\Projecting\PartitionState\DbalProjectionStateStorageBuilder;
use Ecotone\EventSourcing\Projecting\StreamSource\EventStoreAggregateStreamSourceBuilder;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Projecting\Attribute\Projection;
use Ecotone\Projecting\ProjectionRegistry;
use Ecotone\Test\LicenceTesting;
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
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
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

    public function test_asynchronous_projection(): void
    {
        $connectionFactory = self::getConnectionFactory();
        $projection = new #[Asynchronous(self::ASYNC_CHANNEL)] class ($connectionFactory->establishConnection()) extends DbalTicketProjection {
            public const ASYNC_CHANNEL = 'async_projection';
        };
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            [$projection::class, Ticket::class, TicketEventConverter::class, TicketAssigned::class],
            [$connectionFactory, $projection, new TicketEventConverter()],
            runForProductionEventStore: true,
            enableAsynchronousProcessing: [
                SimpleMessageChannelBuilder::createQueueChannel($projection::ASYNC_CHANNEL),
            ],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ticketsCount = $ecotone->deleteEventStream(Ticket::STREAM_NAME)
            ->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME)
            ->sendCommand(new CreateTicketCommand($ticketId = Uuid::uuid4()->toString()))
            ->sendCommandWithRoutingKey(Ticket::ASSIGN_COMMAND, metadata: ['aggregate.id' => $ticketId])
            ->sendQueryWithRouting('getTicketsCount');

        self::assertSame(0, $ticketsCount);

        $ticketsCount = $ecotone->run($projection::ASYNC_CHANNEL, ExecutionPollingMetadata::createWithTestingSetup())
            ->sendQueryWithRouting('getTicketsCount');

        self::assertSame(1, $ticketsCount);
        self::assertSame('assigned', $ecotone->sendQueryWithRouting('getTicketStatus', $ticketId));
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
            licenceKey: LicenceTesting::VALID_LICENCE,
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

    public function test_auto_initialization_mode_processes_events(): void
    {
        $connectionFactory = self::getConnectionFactory();
        $projection = new #[Projection('auto_init_projection', automaticInitialization: true)] class ($connectionFactory->establishConnection()) extends DbalTicketProjection {
            public const NAME = 'auto_init_projection';
            public int $initCallCount = 0;

            #[ProjectionInitialization]
            public function init(): void
            {
                parent::init();
                $this->initCallCount++;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            [$projection::class, Ticket::class, TicketEventConverter::class, TicketAssigned::class],
            [$connectionFactory, $projection, new TicketEventConverter()],
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        // Delete any existing data
        $ecotone->deleteEventStream(Ticket::STREAM_NAME)
            ->deleteProjection($projection::NAME);

        // Send events - should auto-initialize and process
        $ticketId = Uuid::uuid4()->toString();
        $ticketsCount = $ecotone->sendCommand(new CreateTicketCommand($ticketId))
            ->sendCommandWithRoutingKey(Ticket::ASSIGN_COMMAND, metadata: ['aggregate.id' => $ticketId])
            ->sendQueryWithRouting('getTicketsCount');

        self::assertSame(1, $ticketsCount, 'Projection should process events in auto mode');
        self::assertSame('assigned', $ecotone->sendQueryWithRouting('getTicketStatus', $ticketId));
        self::assertSame(1, $projection->initCallCount, 'Init should be called once in auto mode');
    }

    public function test_skip_initialization_mode_skips_events(): void
    {
        $connectionFactory = self::getConnectionFactory();
        $projection = new #[Projection('skip_init_projection', automaticInitialization: false)] class ($connectionFactory->establishConnection()) extends DbalTicketProjection {
            public const NAME = 'skip_init_projection';
            public int $initCallCount = 0;

            #[ProjectionInitialization]
            public function init(): void
            {
                parent::init();
                $this->initCallCount++;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            [$projection::class, Ticket::class, TicketEventConverter::class, TicketAssigned::class],
            [$connectionFactory, $projection, new TicketEventConverter()],
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        // Delete any existing data
        $ecotone->deleteEventStream(Ticket::STREAM_NAME)
            ->deleteProjection($projection::NAME);

        // Send events - should skip processing
        $ticketId = Uuid::uuid4()->toString();
        $ecotone->sendCommand(new CreateTicketCommand($ticketId))
            ->sendCommandWithRoutingKey(Ticket::ASSIGN_COMMAND, metadata: ['aggregate.id' => $ticketId]);

        self::assertSame(0, $projection->initCallCount, 'Init should not be called in skip mode');
    }

    public function test_force_execution_bypasses_skip_mode(): void
    {
        $connectionFactory = self::getConnectionFactory();
        $projection = new #[Projection('force_skip_projection', automaticInitialization: false)] class ($connectionFactory->establishConnection()) extends DbalTicketProjection {
            public const NAME = 'force_skip_projection';
            public int $initCallCount = 0;

            #[ProjectionInitialization]
            public function init(): void
            {
                parent::init();
                $this->initCallCount++;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            [$projection::class, Ticket::class, TicketEventConverter::class, TicketAssigned::class],
            [$connectionFactory, $projection, new TicketEventConverter()],
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        // Delete any existing data
        $ecotone->deleteEventStream(Ticket::STREAM_NAME)
            ->deleteProjection($projection::NAME);

        // Send events first (should be skipped)
        $ticketId = Uuid::uuid4()->toString();
        $ecotone->sendCommand(new CreateTicketCommand($ticketId))
            ->sendCommandWithRoutingKey(Ticket::ASSIGN_COMMAND, metadata: ['aggregate.id' => $ticketId]);

        // Force execution - should initialize and process events
        $ticketsCount = $ecotone->triggerProjection($projection::NAME)
            ->sendQueryWithRouting('getTicketsCount');

        self::assertSame(1, $ticketsCount, 'Projection should process events when forced');
        self::assertSame('assigned', $ecotone->sendQueryWithRouting('getTicketStatus', $ticketId));
        self::assertSame(1, $projection->initCallCount, 'Init should be called when forced');
    }

    public function test_concurrent_initialization_protection(): void
    {
        $connectionFactory = self::getConnectionFactory();
        $projection = new #[Projection('concurrent_projection', automaticInitialization: true)] class ($connectionFactory->establishConnection()) extends DbalTicketProjection {
            public const NAME = 'concurrent_projection';
            public int $initCallCount = 0;

            #[ProjectionInitialization]
            public function init(): void
            {
                parent::init();
                $this->initCallCount++;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            [$projection::class, Ticket::class, TicketEventConverter::class, TicketAssigned::class],
            [$connectionFactory, $projection, new TicketEventConverter()],
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        // Delete any existing data
        $ecotone->deleteEventStream(Ticket::STREAM_NAME)
            ->deleteProjection($projection::NAME);

        // Send multiple events concurrently - should only initialize once
        $ticketId1 = Uuid::uuid4()->toString();
        $ticketId2 = Uuid::uuid4()->toString();
        $ticketId3 = Uuid::uuid4()->toString();

        $ticketsCount = $ecotone->sendCommand(new CreateTicketCommand($ticketId1))
            ->sendCommand(new CreateTicketCommand($ticketId2))
            ->sendCommand(new CreateTicketCommand($ticketId3))
            ->sendCommandWithRoutingKey(Ticket::ASSIGN_COMMAND, metadata: ['aggregate.id' => $ticketId1])
            ->sendCommandWithRoutingKey(Ticket::ASSIGN_COMMAND, metadata: ['aggregate.id' => $ticketId2])
            ->sendCommandWithRoutingKey(Ticket::ASSIGN_COMMAND, metadata: ['aggregate.id' => $ticketId3])
            ->sendQueryWithRouting('getTicketsCount');

        self::assertSame(3, $ticketsCount, 'All events should be processed');
        self::assertSame(1, $projection->initCallCount, 'Init should only be called once due to concurrency protection');
    }

    public function test_projection_state_persistence_across_restarts(): void
    {
        $connectionFactory = self::getConnectionFactory();
        $projection = new #[Projection('persistent_projection', automaticInitialization: true)] class ($connectionFactory->establishConnection()) extends DbalTicketProjection {
            public const NAME = 'persistent_projection';
            public int $initCallCount = 0;

            #[ProjectionInitialization]
            public function init(): void
            {
                parent::init();
                $this->initCallCount++;
            }
        };

        // First run - initialize projection
        $ecotone1 = EcotoneLite::bootstrapFlowTestingWithEventStore(
            [$projection::class, Ticket::class, TicketEventConverter::class, TicketAssigned::class],
            [$connectionFactory, $projection, new TicketEventConverter()],
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone1->deleteEventStream(Ticket::STREAM_NAME)
            ->deleteProjection($projection::NAME);

        $ticketId1 = Uuid::uuid4()->toString();
        $ticketsCount1 = $ecotone1->sendCommand(new CreateTicketCommand($ticketId1))
            ->sendCommandWithRoutingKey(Ticket::ASSIGN_COMMAND, metadata: ['aggregate.id' => $ticketId1])
            ->sendQueryWithRouting('getTicketsCount');

        self::assertSame(1, $ticketsCount1, 'First run should process events');
        self::assertSame(1, $projection->initCallCount, 'Init should be called on first run');

        // Reset projection state for second run
        $projection->initCallCount = 0;

        // Second run - projection should already be initialized
        $ecotone2 = EcotoneLite::bootstrapFlowTestingWithEventStore(
            [$projection::class, Ticket::class, TicketEventConverter::class, TicketAssigned::class],
            [$connectionFactory, $projection, new TicketEventConverter()],
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ticketId2 = Uuid::uuid4()->toString();
        $ticketsCount2 = $ecotone2->sendCommand(new CreateTicketCommand($ticketId2))
            ->sendCommandWithRoutingKey(Ticket::ASSIGN_COMMAND, metadata: ['aggregate.id' => $ticketId2])
            ->sendQueryWithRouting('getTicketsCount');

        // Should process new events but not re-initialize
        self::assertSame(2, $ticketsCount2, 'Second run should process new events');
        self::assertSame(0, $projection->initCallCount, 'Init should not be called on second run');
    }

    public function test_partitioned_projection_with_auto_mode(): void
    {
        $connectionFactory = self::getConnectionFactory();
        $projection = new #[Projection('partitioned_auto_projection', partitionHeaderName: MessageHeaders::EVENT_AGGREGATE_ID, automaticInitialization: true)] class ($connectionFactory->establishConnection()) extends DbalTicketProjection {
            public const NAME = 'partitioned_auto_projection';
            public int $initCallCount = 0;

            #[ProjectionInitialization]
            public function init(): void
            {
                parent::init();
                $this->initCallCount++;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            [$projection::class, Ticket::class, TicketEventConverter::class, TicketAssigned::class],
            [$connectionFactory, $projection, new TicketEventConverter()],
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        // Delete any existing data
        $ecotone->deleteEventStream(Ticket::STREAM_NAME)
            ->deleteProjection($projection::NAME);

        // Send events for different partitions
        $ticketId1 = Uuid::uuid4()->toString();
        $ticketId2 = Uuid::uuid4()->toString();

        $ticketsCount = $ecotone->sendCommand(new CreateTicketCommand($ticketId1), ['tenantId' => 'tenant-1'])
            ->sendCommand(new CreateTicketCommand($ticketId2), ['tenantId' => 'tenant-2'])
            ->sendCommandWithRoutingKey(Ticket::ASSIGN_COMMAND, metadata: ['aggregate.id' => $ticketId1, 'tenantId' => 'tenant-1'])
            ->sendCommandWithRoutingKey(Ticket::ASSIGN_COMMAND, metadata: ['aggregate.id' => $ticketId2, 'tenantId' => 'tenant-2'])
            ->sendQueryWithRouting('getTicketsCount');

        self::assertSame(2, $ticketsCount, 'Partitioned projection should process all events');
        self::assertSame(2, $projection->initCallCount, 'Init should be called once for each partition');
    }
}
