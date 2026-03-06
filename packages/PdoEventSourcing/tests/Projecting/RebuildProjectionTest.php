<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Ecotone\EventSourcing\Attribute\FromStream;
use Ecotone\EventSourcing\Attribute\ProjectionDelete;
use Ecotone\EventSourcing\Attribute\ProjectionInitialization;
use Ecotone\EventSourcing\Attribute\ProjectionReset;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Lite\Test\TestConfiguration;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Projecting\Attribute\PartitionAggregateId;
use Ecotone\Projecting\Attribute\Partitioned;
use Ecotone\Projecting\Attribute\ProjectionExecution;
use Ecotone\Projecting\Attribute\ProjectionRebuild;
use Ecotone\Projecting\Attribute\ProjectionV2;
use Ecotone\Test\LicenceTesting;
use InvalidArgumentException;
use RuntimeException;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\ChangeAssignedPerson;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\CloseTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\AssignedPersonWasChanged;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasClosed;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;

abstract class AbstractRebuildGlobalProjection
{
    public function __construct(protected Connection $connection)
    {
    }

    abstract protected function tableName(): string;

    #[EventHandler]
    public function addTicket(TicketWasRegistered $event): void
    {
        $this->connection->executeStatement(
            "INSERT INTO {$this->tableName()} VALUES (?,?)",
            [$event->getTicketId(), $event->getTicketType()]
        );
    }

    #[ProjectionInitialization]
    public function initialization(): void
    {
        $this->connection->executeStatement(
            "CREATE TABLE IF NOT EXISTS {$this->tableName()} (ticket_id VARCHAR(36) PRIMARY KEY, ticket_type VARCHAR(25))"
        );
    }

    #[ProjectionDelete]
    public function delete(): void
    {
        $this->connection->executeStatement("DROP TABLE IF EXISTS {$this->tableName()}");
    }

    #[ProjectionReset]
    public function reset(): void
    {
        $this->connection->executeStatement("DELETE FROM {$this->tableName()}");
    }

    public function getTickets(): array
    {
        return $this->connection->executeQuery("SELECT * FROM {$this->tableName()} ORDER BY ticket_id ASC")->fetchAllAssociative();
    }
}

abstract class AbstractRebuildPartitionedProjection
{
    public function __construct(protected Connection $connection)
    {
    }

    abstract protected function tableName(): string;

    #[EventHandler]
    public function addTicket(TicketWasRegistered $event): void
    {
        $this->connection->executeStatement(
            "INSERT INTO {$this->tableName()} VALUES (?,?)",
            [$event->getTicketId(), $event->getTicketType()]
        );
    }

    #[ProjectionInitialization]
    public function initialization(): void
    {
        $this->connection->executeStatement(
            "CREATE TABLE IF NOT EXISTS {$this->tableName()} (ticket_id VARCHAR(36) PRIMARY KEY, ticket_type VARCHAR(25))"
        );
    }

    #[ProjectionDelete]
    public function delete(): void
    {
        $this->connection->executeStatement("DROP TABLE IF EXISTS {$this->tableName()}");
    }

    #[ProjectionReset]
    public function reset(
        #[PartitionAggregateId] string $aggregateId,
    ): void {
        $this->connection->executeStatement("DELETE FROM {$this->tableName()} WHERE ticket_id = ?", [$aggregateId]);
    }

    public function getTickets(): array
    {
        return $this->connection->executeQuery("SELECT * FROM {$this->tableName()} ORDER BY ticket_id ASC")->fetchAllAssociative();
    }
}

#[ProjectionV2('rebuild_rollback')]
#[Partitioned]
#[ProjectionRebuild]
#[ProjectionExecution(eventLoadingBatchSize: 3)]
#[FromStream(stream: Ticket::class, aggregateType: Ticket::class)]
class RebuildRollbackProjection
{
    public bool $shouldFailOnProjection = false;
    public int $projectedEventsCount = 0;

    public function __construct(private Connection $connection)
    {
    }

    #[EventHandler]
    public function onTicketRegistered(TicketWasRegistered $event): void
    {
        $this->handleEvent($event->getTicketId());
        if ($this->connection->getDatabasePlatform() instanceof MySQLPlatform) {
            $this->connection->executeStatement('INSERT IGNORE INTO rebuild_rollback_tickets VALUES (?,?)', [$event->getTicketId(), $event->getTicketType()]);
        } else {
            $this->connection->executeStatement('INSERT INTO rebuild_rollback_tickets VALUES (?,?) ON CONFLICT(ticket_id) DO NOTHING', [$event->getTicketId(), $event->getTicketType()]);
        }
    }

    #[EventHandler]
    public function onAssignedPersonChanged(AssignedPersonWasChanged $event): void
    {
        $this->handleEvent($event->getTicketId());
    }

    #[EventHandler]
    public function onTicketClosed(TicketWasClosed $event): void
    {
        $this->handleEvent($event->getTicketId());
    }

    private function handleEvent(string $ticketId): void
    {
        if ($this->shouldFailOnProjection) {
            $this->projectedEventsCount++;
            if ($this->projectedEventsCount >= 4) {
                throw new RuntimeException('Projection failed on 4th event during rebuild');
            }
        }
    }

    #[ProjectionInitialization]
    public function initialization(): void
    {
        $this->connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS rebuild_rollback_tickets (ticket_id VARCHAR(36) PRIMARY KEY, ticket_type VARCHAR(25))'
        );
    }

    #[ProjectionDelete]
    public function delete(): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS rebuild_rollback_tickets');
    }

    #[ProjectionReset]
    public function reset(
        #[PartitionAggregateId] string $aggregateId,
    ): void {
        $this->connection->executeStatement('DELETE FROM rebuild_rollback_tickets WHERE ticket_id = ?', [$aggregateId]);
    }

    #[QueryHandler('getRebuildRollbackTickets')]
    public function query(): array
    {
        return $this->connection->executeQuery('SELECT * FROM rebuild_rollback_tickets ORDER BY ticket_id ASC')->fetchAllAssociative();
    }
}

/**
 * licence Enterprise
 * @internal
 */
final class RebuildProjectionTest extends ProjectingTestCase
{
    public function test_throws_exception_when_rebuild_batch_size_is_less_than_one(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Rebuild partition batch size must be at least 1');

        $connection = $this->getConnection();
        $projection = new #[ ProjectionV2('rebuild_batch0_projection'), Partitioned, ProjectionRebuild(partitionBatchSize: 0), FromStream(stream: Ticket::class, aggregateType: Ticket::class) ] class ($connection) extends AbstractRebuildPartitionedProjection {
            protected function tableName(): string
            {
                return 'rebuild_batch0_tickets';
            }
        };

        $this->bootstrapEcotone([$projection::class], [$projection], true);
    }

    public function test_partitioned_projection_async_rebuild_with_batch_of_2(): void
    {
        $connection = $this->getConnection();
        $projection = new #[ ProjectionV2('rebuild_batch2_async'), Partitioned, ProjectionRebuild(partitionBatchSize: 2, asyncChannelName: 'rebuild_channel'), FromStream(stream: Ticket::class, aggregateType: Ticket::class) ] class ($connection) extends AbstractRebuildPartitionedProjection {
            #[QueryHandler('getRebuildBatch2Tickets')]
            public function query(): array
            {
                return $this->getTickets();
            }

            protected function tableName(): string
            {
                return 'rebuild_batch2_tickets';
            }
        };

        $ecotone = $this->bootstrapEcotone(
            [$projection::class],
            [$projection],
            [SimpleMessageChannelBuilder::createQueueChannel('rebuild_channel')],
            TestConfiguration::createWithDefaults()->withSpyOnChannel('rebuild_channel')
        );

        $this->createPartitions($ecotone, 5);
        self::assertCount(5, $ecotone->sendQueryWithRouting('getRebuildBatch2Tickets'));

        $ecotone->runConsoleCommand('ecotone:projection:rebuild', ['name' => 'rebuild_batch2_async']);

        $messages = $ecotone->getRecordedMessagePayloadsFrom('rebuild_channel');
        self::assertCount(3, $messages);

        $ecotone->run('rebuild_channel', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1));
        $ecotone->run('rebuild_channel', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1));
        $ecotone->run('rebuild_channel', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1));
        self::assertCount(5, $ecotone->sendQueryWithRouting('getRebuildBatch2Tickets'));
    }

    public function test_partitioned_projection_sync_rebuild(): void
    {
        $connection = $this->getConnection();
        $projection = new #[ ProjectionV2('rebuild_sync_partitioned'), Partitioned, ProjectionRebuild(partitionBatchSize: 2), FromStream(stream: Ticket::class, aggregateType: Ticket::class) ] class ($connection) extends AbstractRebuildPartitionedProjection {
            #[QueryHandler('getRebuildSyncPartitionedTickets')]
            public function query(): array
            {
                return $this->getTickets();
            }

            protected function tableName(): string
            {
                return 'rebuild_sync_partitioned_tickets';
            }
        };

        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection], true);

        $this->createPartitions($ecotone, 5);

        $ecotone->runConsoleCommand('ecotone:projection:rebuild', ['name' => 'rebuild_sync_partitioned']);

        self::assertCount(5, $ecotone->sendQueryWithRouting('getRebuildSyncPartitionedTickets'));
    }

    public function test_global_projection_async_rebuild(): void
    {
        $connection = $this->getConnection();
        $projection = new #[ ProjectionV2('rebuild_global_async'), ProjectionRebuild(asyncChannelName: 'rebuild_global_channel'), FromStream(Ticket::class) ] class ($connection) extends AbstractRebuildGlobalProjection {
            #[QueryHandler('getRebuildGlobalAsyncTickets')]
            public function query(): array
            {
                return $this->getTickets();
            }

            protected function tableName(): string
            {
                return 'rebuild_global_async_tickets';
            }
        };

        $ecotone = $this->bootstrapEcotone(
            [$projection::class],
            [$projection],
            [SimpleMessageChannelBuilder::createQueueChannel('rebuild_global_channel')],
            TestConfiguration::createWithDefaults()->withSpyOnChannel('rebuild_global_channel')
        );

        $this->createTickets($ecotone, 3);

        $ecotone->runConsoleCommand('ecotone:projection:rebuild', ['name' => 'rebuild_global_async']);

        self::assertCount(3, $ecotone->sendQueryWithRouting('getRebuildGlobalAsyncTickets'));

        $messages = $ecotone->getRecordedMessagePayloadsFrom('rebuild_global_channel');
        self::assertCount(1, $messages);

        $ecotone->run('rebuild_global_channel', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1));
        self::assertCount(3, $ecotone->sendQueryWithRouting('getRebuildGlobalAsyncTickets'));
    }

    public function test_global_projection_sync_rebuild(): void
    {
        $connection = $this->getConnection();
        $projection = new #[ ProjectionV2('rebuild_global_sync'), ProjectionRebuild, FromStream(Ticket::class) ] class ($connection) extends AbstractRebuildGlobalProjection {
            #[QueryHandler('getRebuildGlobalSyncTickets')]
            public function query(): array
            {
                return $this->getTickets();
            }

            protected function tableName(): string
            {
                return 'rebuild_global_sync_tickets';
            }
        };

        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection], true);

        $this->createTickets($ecotone, 3);

        $ecotone->runConsoleCommand('ecotone:projection:rebuild', ['name' => 'rebuild_global_sync']);

        self::assertCount(3, $ecotone->sendQueryWithRouting('getRebuildGlobalSyncTickets'));
    }

    public function test_rebuild_resets_existing_data(): void
    {
        $connection = $this->getConnection();
        $projection = new #[ ProjectionV2('rebuild_resets_data'), Partitioned, ProjectionRebuild, FromStream(stream: Ticket::class, aggregateType: Ticket::class) ] class ($connection) extends AbstractRebuildPartitionedProjection {
            #[QueryHandler('getRebuildResetsDataTickets')]
            public function query(): array
            {
                return $this->getTickets();
            }

            protected function tableName(): string
            {
                return 'rebuild_resets_data_tickets';
            }
        };

        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection], true);

        $ecotone->sendCommand(new RegisterTicket('1', 'User1', 'alert'));
        $ecotone->sendCommand(new RegisterTicket('2', 'User2', 'info'));

        self::assertCount(2, $ecotone->sendQueryWithRouting('getRebuildResetsDataTickets'));

        $ecotone->runConsoleCommand('ecotone:projection:rebuild', ['name' => 'rebuild_resets_data']);

        $tickets = $ecotone->sendQueryWithRouting('getRebuildResetsDataTickets');
        self::assertCount(2, $tickets);
        self::assertSame('1', $tickets[0]['ticket_id']);
        self::assertSame('2', $tickets[1]['ticket_id']);
    }

    public function test_rebuild_rolls_back_on_exception_during_reprojection(): void
    {
        $connection = $this->getConnection();
        $projection = new RebuildRollbackProjection($connection);

        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection], true);

        $ecotone->sendCommand(new RegisterTicket('1', 'User1', 'alert'));
        $ecotone->sendCommand(new ChangeAssignedPerson('1', 'User2'));
        $ecotone->sendCommand(new ChangeAssignedPerson('1', 'User3'));
        $ecotone->sendCommand(new ChangeAssignedPerson('1', 'User4'));
        $ecotone->sendCommand(new CloseTicket('1'));

        self::assertCount(1, $ecotone->sendQueryWithRouting('getRebuildRollbackTickets'));
        self::assertTrue($ecotone->sendQueryWithRouting('ticket.isClosed', metadata: ['aggregate.id' => '1']));

        $projection->shouldFailOnProjection = true;

        $thrownException = false;
        try {
            $ecotone->runConsoleCommand('ecotone:projection:rebuild', ['name' => 'rebuild_rollback']);
        } catch (RuntimeException $e) {
            $thrownException = true;
        }

        self::assertTrue($thrownException, 'Expected RuntimeException to be thrown during rebuild');
        self::assertCount(1, $ecotone->sendQueryWithRouting('getRebuildRollbackTickets'));
        self::assertTrue($ecotone->sendQueryWithRouting('ticket.isClosed', metadata: ['aggregate.id' => '1']));
    }

    private function createPartitions(FlowTestSupport $ecotone, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $ecotone->sendCommand(new RegisterTicket((string) $i, "User{$i}", "type{$i}"));
        }
    }

    private function createTickets(FlowTestSupport $ecotone, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $ecotone->sendCommand(new RegisterTicket((string) $i, "User{$i}", 'alert'));
        }
    }

    private function bootstrapEcotone(array $classesToResolve, array $services, bool|array $channels, ?TestConfiguration $testConfiguration = null): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [...$classesToResolve, Ticket::class, TicketEventConverter::class],
            containerOrAvailableServices: [...$services, new TicketEventConverter(), self::getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([
                    ModulePackageList::DBAL_PACKAGE,
                    ModulePackageList::EVENT_SOURCING_PACKAGE,
                    ModulePackageList::ASYNCHRONOUS_PACKAGE,
                ])),
            runForProductionEventStore: true,
            enableAsynchronousProcessing: $channels,
            licenceKey: LicenceTesting::VALID_LICENCE,
            testConfiguration: $testConfiguration,
        );
    }
}
