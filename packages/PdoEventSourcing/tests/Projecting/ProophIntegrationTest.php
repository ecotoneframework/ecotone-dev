<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting;

use Doctrine\ORM\EntityManagerInterface;
use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\DbalConnection;
use Ecotone\Dbal\ManagerRegistryEmulator;
use Ecotone\EventSourcing\Attribute\FromStream;
use Ecotone\EventSourcing\Attribute\ProjectionInitialization;
use Ecotone\EventSourcing\Attribute\ProjectionState;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Projecting\Attribute\Partitioned;
use Ecotone\Projecting\Attribute\ProjectionDeployment;
use Ecotone\Projecting\Attribute\ProjectionExecution;
use Ecotone\Projecting\Attribute\ProjectionFlush;
use Ecotone\Projecting\Attribute\ProjectionV2;
use Ecotone\Test\LicenceTesting;
use Enqueue\Dbal\DbalConnectionFactory;
use Symfony\Component\Uid\Uuid;
use Test\Ecotone\EventSourcing\Fixture\Basket\Basket;
use Test\Ecotone\EventSourcing\Fixture\Basket\BasketEventConverter;
use Test\Ecotone\EventSourcing\Fixture\Basket\Command\CreateBasket;
use Test\Ecotone\EventSourcing\Fixture\Basket\Event\BasketWasCreated;
use Test\Ecotone\EventSourcing\Projecting\Fixture\DbalTicketProjection;
use Test\Ecotone\EventSourcing\Projecting\Fixture\ProjectedTicketEntity;
use Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket\CreateTicketCommand;
use Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket\TicketAssigned;
use Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket\TicketCreated;
use Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket\TicketEventConverter;

/**
 * @internal
 */
class ProophIntegrationTest extends ProjectingTestCase
{
    public function test_it_can_project_events(): void
    {
        $connectionFactory = self::getConnectionFactory();
        $projection = new #[ProjectionV2(self::NAME), FromStream(Ticket::STREAM_NAME)] class ($connectionFactory->establishConnection()) extends DbalTicketProjection {
            public const NAME = 'dbal_tickets_projection';
        };
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            [$projection::class, Ticket::class, TicketEventConverter::class, TicketAssigned::class],
            [$connectionFactory, $projection, new TicketEventConverter()],
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ticketsCount = $ecotone->deleteEventStream(Ticket::STREAM_NAME)
            ->deleteProjection($projection::NAME)
            ->sendCommand(new CreateTicketCommand($ticketId = Uuid::v7()->toRfc4122()))
            ->sendCommandWithRoutingKey(Ticket::ASSIGN_COMMAND, metadata: ['aggregate.id' => $ticketId])
            ->sendQueryWithRouting('getTicketsCount');

        self::assertSame(1, $ticketsCount);
        self::assertSame('assigned', $ecotone->sendQueryWithRouting('getTicketStatus', $ticketId));

        $ticketsCount = $ecotone->deleteProjection($projection::NAME)
            ->initializeProjection($projection::NAME)
            ->sendQueryWithRouting('getTicketsCount');

        self::assertSame(0, $ticketsCount);

        $ticketsCount = $ecotone->triggerProjection($projection::NAME)
            ->sendQueryWithRouting('getTicketsCount');

        self::assertSame(1, $ticketsCount);
        self::assertSame('assigned', $ecotone->sendQueryWithRouting('getTicketStatus', $ticketId));
    }

    public function test_asynchronous_projection(): void
    {
        $connectionFactory = self::getConnectionFactory();
        $projection = new #[ProjectionV2(self::NAME), FromStream(Ticket::STREAM_NAME), Asynchronous(self::ASYNC_CHANNEL)] class ($connectionFactory->establishConnection()) extends DbalTicketProjection {
            public const NAME = 'async_dbal_tickets_projection';
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
            ->sendCommand(new CreateTicketCommand($ticketId = Uuid::v7()->toRfc4122()))
            ->sendCommandWithRoutingKey(Ticket::ASSIGN_COMMAND, metadata: ['aggregate.id' => $ticketId])
            ->sendQueryWithRouting('getTicketsCount');

        self::assertSame(0, $ticketsCount);

        $ticketsCount = $ecotone->run($projection::ASYNC_CHANNEL, ExecutionPollingMetadata::createWithTestingSetup())
            ->sendQueryWithRouting('getTicketsCount');

        self::assertSame(1, $ticketsCount);
        self::assertSame('assigned', $ecotone->sendQueryWithRouting('getTicketStatus', $ticketId));
    }

    public function test_auto_initialization_mode_processes_events(): void
    {
        $connectionFactory = self::getConnectionFactory();
        $projection = new #[ProjectionV2('auto_init_projection'), ProjectionDeployment(manualKickOff: false), FromStream(Ticket::STREAM_NAME)] class ($connectionFactory->establishConnection()) extends DbalTicketProjection {
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
        $ticketId = Uuid::v7()->toRfc4122();
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
        $projection = new #[ProjectionV2('skip_init_projection'), ProjectionDeployment(manualKickOff: true), FromStream(Ticket::STREAM_NAME)] class ($connectionFactory->establishConnection()) extends DbalTicketProjection {
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
        $ticketId = Uuid::v7()->toRfc4122();
        $ecotone->sendCommand(new CreateTicketCommand($ticketId))
            ->sendCommandWithRoutingKey(Ticket::ASSIGN_COMMAND, metadata: ['aggregate.id' => $ticketId]);

        self::assertSame(0, $projection->initCallCount, 'Init should not be called in skip mode');
    }

    public function test_force_execution_bypasses_skip_mode(): void
    {
        $connectionFactory = self::getConnectionFactory();
        $projection = new #[ProjectionV2('force_skip_projection'), ProjectionDeployment(manualKickOff: true), FromStream(Ticket::STREAM_NAME)] class ($connectionFactory->establishConnection()) extends DbalTicketProjection {
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
        $ticketId = Uuid::v7()->toRfc4122();
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
        $projection = new #[ProjectionV2('concurrent_projection'), ProjectionDeployment(manualKickOff: false), FromStream(Ticket::STREAM_NAME)] class ($connectionFactory->establishConnection()) extends DbalTicketProjection {
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
        $ticketId1 = Uuid::v7()->toRfc4122();
        $ticketId2 = Uuid::v7()->toRfc4122();
        $ticketId3 = Uuid::v7()->toRfc4122();

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
        $projection = new #[ProjectionV2('persistent_projection'), ProjectionDeployment(manualKickOff: false), FromStream(Ticket::STREAM_NAME)] class ($connectionFactory->establishConnection()) extends DbalTicketProjection {
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

        $ticketId1 = Uuid::v7()->toRfc4122();
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

        $ticketId2 = Uuid::v7()->toRfc4122();
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
        $projection = new #[ProjectionV2('partitioned_auto_projection'), Partitioned, FromStream(Ticket::STREAM_NAME, Ticket::class)] class ($connectionFactory->establishConnection()) extends DbalTicketProjection {
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
        $ticketId1 = Uuid::v7()->toRfc4122();
        $ticketId2 = Uuid::v7()->toRfc4122();

        $ticketsCount = $ecotone->sendCommand(new CreateTicketCommand($ticketId1), ['tenantId' => 'tenant-1'])
            ->sendCommand(new CreateTicketCommand($ticketId2), ['tenantId' => 'tenant-2'])
            ->sendCommandWithRoutingKey(Ticket::ASSIGN_COMMAND, metadata: ['aggregate.id' => $ticketId1, 'tenantId' => 'tenant-1'])
            ->sendCommandWithRoutingKey(Ticket::ASSIGN_COMMAND, metadata: ['aggregate.id' => $ticketId2, 'tenantId' => 'tenant-2'])
            ->sendQueryWithRouting('getTicketsCount');

        self::assertSame(2, $ticketsCount, 'Partitioned projection should process all events');
        self::assertSame(2, $projection->initCallCount, 'Init should be called once for each partition');
    }

    public function test_it_handles_batches(): void
    {
        $connectionFactory = self::getConnectionFactory();
        $projection = new #[ProjectionV2(self::NAME), ProjectionDeployment(manualKickOff: true), FromStream(Ticket::STREAM_NAME), ProjectionExecution(eventLoadingBatchSize: 3)] class ($connectionFactory->establishConnection()) extends DbalTicketProjection {
            public const NAME = 'batch_projection';
            public int $flushCallCount = 0;
            #[ProjectionFlush]
            public function flush(): void
            {
                $this->flushCallCount++;
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

        // Send multiple events
        for ($i = 1; $i <= 5; $i++) {
            $ticketId = Uuid::v7()->toRfc4122();
            $ecotone->sendCommand(new CreateTicketCommand($ticketId))
                ->sendCommandWithRoutingKey(Ticket::ASSIGN_COMMAND, metadata: ['aggregate.id' => $ticketId]);
        }

        // Trigger projection processing
        $ticketsCount = $ecotone->triggerProjection($projection::NAME)
            ->sendQueryWithRouting('getTicketsCount');

        self::assertSame(5, $ticketsCount, 'Batch projection should process all events in batches');
        self::assertSame(4, $projection->flushCallCount, 'Flush should be called 4 times (10 events / batch size 3) = 4 rounded up');
    }

    public function test_flush_receives_projection_state(): void
    {
        $connectionFactory = self::getConnectionFactory();
        $projection = new #[ProjectionV2(self::NAME), ProjectionDeployment(manualKickOff: true), FromStream(Ticket::STREAM_NAME), ProjectionExecution(eventLoadingBatchSize: 3)] class {
            public const NAME = 'flush_state_projection';
            public array $flushStateSnapshots = [];

            #[EventHandler]
            public function whenTicketCreated(TicketCreated $event, #[ProjectionState] array $state = []): array
            {
                $state['ticketCount'] = ($state['ticketCount'] ?? 0) + 1;
                return $state;
            }

            #[ProjectionFlush]
            public function flush(#[ProjectionState] array $state = []): void
            {
                $this->flushStateSnapshots[] = $state;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            [$projection::class, Ticket::class, TicketEventConverter::class, TicketAssigned::class],
            [$connectionFactory, $projection, new TicketEventConverter()],
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->deleteEventStream(Ticket::STREAM_NAME)
            ->deleteProjection($projection::NAME);

        for ($i = 1; $i <= 5; $i++) {
            $ecotone->sendCommand(new CreateTicketCommand(Uuid::v7()->toRfc4122()));
        }

        $ecotone->triggerProjection($projection::NAME);

        // 5 TicketCreated events with batch size 3: batch 1 = 3 events, batch 2 = 2 events
        self::assertCount(2, $projection->flushStateSnapshots);
        self::assertSame(['ticketCount' => 3], $projection->flushStateSnapshots[0]);
        self::assertSame(['ticketCount' => 5], $projection->flushStateSnapshots[1]);
    }

    public function test_it_handles_custom_name_stream_source(): void
    {
        $basketProjection = new #[ProjectionV2(self::NAME), FromStream(Basket::BASKET_STREAM)] class {
            public const NAME = 'basket_projection';
            public int $basketCount = 0;

            #[EventHandler(BasketWasCreated::EVENT_NAME)]
            public function onBasketCreated(): void
            {
                $this->basketCount++;
            }
        };

        EcotoneLite::bootstrapFlowTestingWithEventStore(
            [$basketProjection::class, Basket::class, BasketEventConverter::class, BasketWasCreated::class],
            [self::getConnectionFactory(), $basketProjection, new BasketEventConverter()],
            ServiceConfiguration::createWithDefaults(),
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        )
            ->deleteEventStream(Basket::BASKET_STREAM)
            ->deleteProjection($basketProjection::NAME)
            ->sendCommand(new CreateBasket(Uuid::v7()->toRfc4122()))
            ->sendCommand(new CreateBasket(Uuid::v7()->toRfc4122()));

        self::assertSame(2, $basketProjection->basketCount);
    }

    public function test_it_handles_backfilling_projection_when_stream_does_not_exist(): void
    {
        $connectionFactory = self::getConnectionFactory();
        $projection = new #[ProjectionV2(self::NAME), FromStream(Ticket::STREAM_NAME)] class ($connectionFactory->establishConnection()) extends DbalTicketProjection {
            public const NAME = 'ticket_projection';
            public int $initCallCount = 0;

            #[ProjectionInitialization]
            public function init(): void
            {
                $this->initCallCount++;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            [$projection::class, Ticket::class, TicketEventConverter::class, TicketAssigned::class],
            [$connectionFactory, $projection, new TicketEventConverter()],
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->deleteEventStream(Ticket::STREAM_NAME)
            ->deleteProjection($projection::NAME);

        $ecotone->triggerProjection($projection::NAME);
        $ecotone->triggerProjection($projection::NAME);

        self::assertSame(1, $projection->initCallCount, 'Init should be called once');
    }

    public function test_projecting_with_already_connected_dbal_connection_factory(): void
    {
        $dbalConnectionFactory = self::getConnectionFactory();
        $connection = $dbalConnectionFactory->createContext()->getDbalConnection();
        $alreadyConnectedFactory = DbalConnection::create($connection);

        $projection = new #[ProjectionV2(self::NAME), FromStream(Ticket::STREAM_NAME)] class ($connection) extends DbalTicketProjection {
            public const NAME = 'already_connected_projection';
        };

        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            [$projection::class, Ticket::class, TicketEventConverter::class, TicketAssigned::class],
            [DbalConnectionFactory::class => $alreadyConnectedFactory, $projection, new TicketEventConverter()],
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ticketsCount = $ecotone->deleteEventStream(Ticket::STREAM_NAME)
            ->deleteProjection($projection::NAME)
            ->sendCommand(new CreateTicketCommand($ticketId = Uuid::v7()->toRfc4122()))
            ->sendCommandWithRoutingKey(Ticket::ASSIGN_COMMAND, metadata: ['aggregate.id' => $ticketId])
            ->sendQueryWithRouting('getTicketsCount');

        self::assertSame(1, $ticketsCount);
        self::assertSame('assigned', $ecotone->sendQueryWithRouting('getTicketStatus', $ticketId));
    }

    public function test_partitioned_projection_with_already_connected_dbal_connection_factory(): void
    {
        $dbalConnectionFactory = self::getConnectionFactory();
        $connection = $dbalConnectionFactory->createContext()->getDbalConnection();
        $alreadyConnectedFactory = DbalConnection::create($connection);

        $projection = new #[ProjectionV2(self::NAME), Partitioned, FromStream(Ticket::STREAM_NAME, Ticket::class)] class ($connection) extends DbalTicketProjection {
            public const NAME = 'already_connected_partitioned_projection';
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
            [DbalConnectionFactory::class => $alreadyConnectedFactory, $projection, new TicketEventConverter()],
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->deleteEventStream(Ticket::STREAM_NAME)
            ->deleteProjection($projection::NAME);

        $ticketId1 = Uuid::v7()->toRfc4122();
        $ticketId2 = Uuid::v7()->toRfc4122();

        $ticketsCount = $ecotone->sendCommand(new CreateTicketCommand($ticketId1))
            ->sendCommand(new CreateTicketCommand($ticketId2))
            ->sendCommandWithRoutingKey(Ticket::ASSIGN_COMMAND, metadata: ['aggregate.id' => $ticketId1])
            ->sendCommandWithRoutingKey(Ticket::ASSIGN_COMMAND, metadata: ['aggregate.id' => $ticketId2])
            ->sendQueryWithRouting('getTicketsCount');

        self::assertSame(2, $ticketsCount);
        self::assertSame(2, $projection->initCallCount);
    }

    public function test_object_manager_interceptor_flushes_and_clears_on_each_batch(): void
    {
        $connection = self::getConnection();
        $connection->executeStatement('DROP TABLE IF EXISTS projected_tickets');
        $connection->executeStatement(<<<SQL
                CREATE TABLE projected_tickets (
                    ticket_id VARCHAR(255) PRIMARY KEY,
                    status VARCHAR(255) NOT NULL
                )
            SQL);

        $ormConnectionFactory = ManagerRegistryEmulator::create(
            $connection,
            [__DIR__ . '/Fixture']
        );

        $projection = new #[ProjectionV2(self::NAME), ProjectionDeployment(manualKickOff: true), FromStream(Ticket::STREAM_NAME), ProjectionExecution(eventLoadingBatchSize: 2)] class {
            public const NAME = 'orm_batch_projection';
            private ?EntityManagerInterface $entityManager = null;

            public function setEntityManager(EntityManagerInterface $entityManager): void
            {
                $this->entityManager = $entityManager;
            }

            #[EventHandler]
            public function whenTicketCreated(TicketCreated $event): void
            {
                $this->entityManager->persist(new ProjectedTicketEntity($event->ticketId, 'created'));
            }
        };

        $registry = $ormConnectionFactory->getRegistry();
        $entityManager = $registry->getManager();
        $projection->setEntityManager($entityManager);

        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            [$projection::class, Ticket::class, TicketEventConverter::class, TicketAssigned::class],
            [DbalConnectionFactory::class => $ormConnectionFactory, $projection, new TicketEventConverter()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withExtensionObjects([
                    DbalConfiguration::createForTesting()
                        ->withClearAndFlushObjectManagerOnProjectionBatch(true),
                ]),
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->deleteEventStream(Ticket::STREAM_NAME)
            ->deleteProjection($projection::NAME);

        for ($i = 1; $i <= 5; $i++) {
            $ecotone->sendCommand(new CreateTicketCommand(Uuid::v7()->toRfc4122()));
        }

        $ecotone->triggerProjection($projection::NAME);

        $ticketCount = (int) $connection->fetchOne('SELECT COUNT(*) FROM projected_tickets');
        self::assertSame(5, $ticketCount, 'ObjectManagerInterceptor should flush all entities to database on each batch');

        $identityMap = $entityManager->getUnitOfWork()->getIdentityMap();
        self::assertEmpty(
            $identityMap[ProjectedTicketEntity::class] ?? [],
            'ObjectManagerInterceptor should clear entity manager identity map after each batch'
        );

        $connection->executeStatement('DROP TABLE IF EXISTS projected_tickets');
    }
}
