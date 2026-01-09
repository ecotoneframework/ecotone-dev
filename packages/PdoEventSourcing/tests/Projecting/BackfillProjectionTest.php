<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting;

use Doctrine\DBAL\Connection;
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
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Projecting\Attribute\Partitioned;
use Ecotone\Projecting\Attribute\ProjectionBackfill;
use Ecotone\Projecting\Attribute\ProjectionV2;
use Ecotone\Test\LicenceTesting;
use InvalidArgumentException;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;

abstract class AbstractTicketProjection
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

/**
 * licence Enterprise
 * @internal
 */
final class BackfillProjectionTest extends ProjectingTestCase
{
    public function test_throws_exception_when_backfill_batch_size_is_less_than_one(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Backfill partition batch size must be at least 1');

        $connection = $this->getConnection();
        $projection = new #[ ProjectionV2('batch0_projection'), Partitioned(MessageHeaders::EVENT_AGGREGATE_ID), ProjectionBackfill(backfillPartitionBatchSize: 0), FromStream(stream: Ticket::class, aggregateType: Ticket::class) ] class ($connection) extends AbstractTicketProjection {
            protected function tableName(): string
            {
                return 'batch0_tickets';
            }
        };

        $this->bootstrapEcotone([$projection::class], [$projection], true);
    }

    public function test_partitioned_projection_async_backfill_with_batch_of_2_processes_5_partitions_in_3_runs(): void
    {
        $connection = $this->getConnection();
        $projection = new #[ ProjectionV2('batch2_async_projection'), Partitioned(MessageHeaders::EVENT_AGGREGATE_ID), ProjectionBackfill(backfillPartitionBatchSize: 2, asyncChannelName: 'backfill_channel'), FromStream(stream: Ticket::class, aggregateType: Ticket::class) ] class ($connection) extends AbstractTicketProjection {
            #[QueryHandler('getBackfillTickets')]
            public function query(): array
            {
                return $this->getTickets();
            }

            protected function tableName(): string
            {
                return 'batch2_tickets';
            }
        };

        $ecotone = $this->bootstrapEcotone(
            [$projection::class],
            [$projection],
            [SimpleMessageChannelBuilder::createQueueChannel('backfill_channel')],
            TestConfiguration::createWithDefaults()->withSpyOnChannel('backfill_channel')
        );

        $this->createPartitions($ecotone, 5);

        $ecotone->deleteProjection('batch2_async_projection')
            ->initializeProjection('batch2_async_projection');

        $ecotone->runConsoleCommand('ecotone:projection:backfill', ['name' => 'batch2_async_projection']);

        $messages = $ecotone->getRecordedMessagePayloadsFrom('backfill_channel');
        self::assertCount(3, $messages);

        $ecotone->run('backfill_channel', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1));
        $ecotone->run('backfill_channel', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1));
        self::assertCount(4, $ecotone->sendQueryWithRouting('getBackfillTickets'));

        $ecotone->run('backfill_channel', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1));
        self::assertCount(5, $ecotone->sendQueryWithRouting('getBackfillTickets'));
    }

    public function test_partitioned_projection_async_backfill_with_batch_of_5_completes_in_single_run(): void
    {
        $connection = $this->getConnection();
        $projection = new #[ ProjectionV2('batch5_async_projection'), Partitioned(MessageHeaders::EVENT_AGGREGATE_ID), ProjectionBackfill(backfillPartitionBatchSize: 5, asyncChannelName: 'backfill_channel'), FromStream(stream: Ticket::class, aggregateType: Ticket::class) ] class ($connection) extends AbstractTicketProjection {
            #[QueryHandler('getBackfillTickets5')]
            public function query(): array
            {
                return $this->getTickets();
            }

            protected function tableName(): string
            {
                return 'batch5_tickets';
            }
        };

        $ecotone = $this->bootstrapEcotone(
            [$projection::class],
            [$projection],
            [SimpleMessageChannelBuilder::createQueueChannel('backfill_channel')],
            TestConfiguration::createWithDefaults()->withSpyOnChannel('backfill_channel')
        );

        $this->createPartitions($ecotone, 5);

        $ecotone->deleteProjection('batch5_async_projection')
            ->initializeProjection('batch5_async_projection');

        $ecotone->runConsoleCommand('ecotone:projection:backfill', ['name' => 'batch5_async_projection']);

        $messages = $ecotone->getRecordedMessagePayloadsFrom('backfill_channel');
        self::assertCount(1, $messages);

        $ecotone->run('backfill_channel', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1));
        self::assertCount(5, $ecotone->sendQueryWithRouting('getBackfillTickets5'));
    }

    public function test_partitioned_projection_sync_backfill_processes_all_partitions_immediately(): void
    {
        $connection = $this->getConnection();
        $projection = new #[ ProjectionV2('sync_partitioned_projection'), Partitioned(MessageHeaders::EVENT_AGGREGATE_ID), ProjectionBackfill(backfillPartitionBatchSize: 2), FromStream(stream: Ticket::class, aggregateType: Ticket::class) ] class ($connection) extends AbstractTicketProjection {
            #[QueryHandler('getSyncBackfillTickets')]
            public function query(): array
            {
                return $this->getTickets();
            }

            protected function tableName(): string
            {
                return 'sync_partitioned_tickets';
            }
        };

        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection], true);

        $ecotone->deleteProjection('sync_partitioned_projection')
            ->initializeProjection('sync_partitioned_projection');

        $this->createPartitions($ecotone, 5);

        $ecotone->runConsoleCommand('ecotone:projection:backfill', ['name' => 'sync_partitioned_projection']);

        self::assertCount(5, $ecotone->sendQueryWithRouting('getSyncBackfillTickets'));
    }

    public function test_global_projection_async_backfill_processes_all_events_after_running_channel(): void
    {
        $connection = $this->getConnection();
        $projection = new #[ ProjectionV2('global_async_projection'), ProjectionBackfill(asyncChannelName: 'backfill_global_channel'), FromStream(Ticket::class) ] class ($connection) extends AbstractTicketProjection {
            #[QueryHandler('getGlobalAsyncTickets')]
            public function query(): array
            {
                return $this->getTickets();
            }

            protected function tableName(): string
            {
                return 'global_async_tickets';
            }
        };

        $ecotone = $this->bootstrapEcotone(
            [$projection::class],
            [$projection],
            [SimpleMessageChannelBuilder::createQueueChannel('backfill_global_channel')],
            TestConfiguration::createWithDefaults()->withSpyOnChannel('backfill_global_channel')
        );

        $this->createTickets($ecotone, 3);

        $ecotone->deleteProjection('global_async_projection')
            ->initializeProjection('global_async_projection');

        $ecotone->runConsoleCommand('ecotone:projection:backfill', ['name' => 'global_async_projection']);

        self::assertCount(0, $ecotone->sendQueryWithRouting('getGlobalAsyncTickets'));

        $messages = $ecotone->getRecordedMessagePayloadsFrom('backfill_global_channel');
        self::assertCount(1, $messages);

        $ecotone->run('backfill_global_channel', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1));
        self::assertCount(3, $ecotone->sendQueryWithRouting('getGlobalAsyncTickets'));
    }

    public function test_global_projection_sync_backfill_processes_all_events_immediately(): void
    {
        $connection = $this->getConnection();
        $projection = new #[ ProjectionV2('global_sync_projection'), ProjectionBackfill(), FromStream(Ticket::class) ] class ($connection) extends AbstractTicketProjection {
            #[QueryHandler('getGlobalSyncTickets')]
            public function query(): array
            {
                return $this->getTickets();
            }

            protected function tableName(): string
            {
                return 'global_sync_tickets';
            }
        };

        $ecotone = $this->bootstrapEcotone([$projection::class], [$projection], true);

        $ecotone->deleteProjection('global_sync_projection')
            ->initializeProjection('global_sync_projection');

        $this->createTickets($ecotone, 3);

        $ecotone->runConsoleCommand('ecotone:projection:backfill', ['name' => 'global_sync_projection']);

        self::assertCount(3, $ecotone->sendQueryWithRouting('getGlobalSyncTickets'));
    }

    private function createPartitions(FlowTestSupport $ecotone, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $ecotone->sendCommand(new RegisterTicket((string)$i, "User{$i}", "type{$i}"));
        }
    }

    private function createTickets(FlowTestSupport $ecotone, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $ecotone->sendCommand(new RegisterTicket((string)$i, "User{$i}", 'alert'));
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
