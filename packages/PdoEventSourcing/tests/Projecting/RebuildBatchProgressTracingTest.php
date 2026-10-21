<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting;

use Doctrine\DBAL\Connection;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Projecting\FromAggregateStream;
use Ecotone\Api\Projecting\PartitionAggregateId;
use Ecotone\Api\Projecting\Partitioned;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Projecting\ProjectionDelete;
use Ecotone\Api\Projecting\ProjectionInitialization;
use Ecotone\Api\Projecting\ProjectionRebuild;
use Ecotone\Api\Projecting\ProjectionReset;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Api\Tracing\TracingConfiguration;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;

/**
 * @internal
 */
final class RebuildBatchProgressTracingTest extends ProjectingTestCase
{
    private const BATCH_NUMBER = 'ecotone_projecting_rebuild_batch_number';
    private const BATCH_COUNT = 'ecotone_projecting_rebuild_batch_count';
    private const PARTITION_COUNT = 'ecotone_projecting_rebuild_partition_count';

    public function test_every_rebuild_batch_span_reports_its_number_the_batch_count_and_the_partition_count(): void
    {
        $recorder = new InMemorySpanRecorder();
        $ecotone = $this->bootstrapPartitionedRebuild($recorder, TracingConfiguration::createWithDefaults()->withSpanRecorder(InMemorySpanRecorder::class));

        $this->rebuildFiveTicketsInBatchesOfTwo($ecotone);

        self::assertSame(
            [[1, 3, 5], [2, 3, 5], [3, 3, 5]],
            $this->batchProgressOf($recorder),
        );
    }

    public function test_the_batch_progress_is_recorded_when_the_span_attributes_are_narrowed_to_other_headers(): void
    {
        $recorder = new InMemorySpanRecorder();
        $ecotone = $this->bootstrapPartitionedRebuild($recorder, TracingConfiguration::createWithDefaults()->withSpanRecorder(InMemorySpanRecorder::class)->withSpanAttributesFromHeaders('tenant'));

        $this->rebuildFiveTicketsInBatchesOfTwo($ecotone);

        self::assertSame(
            [[1, 3, 5], [2, 3, 5], [3, 3, 5]],
            $this->batchProgressOf($recorder),
        );
    }

    private function rebuildFiveTicketsInBatchesOfTwo(FlowTestSupport $ecotone): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $ecotone->sendCommand(new RegisterTicket((string) $i, "User{$i}", "type{$i}"));
        }
        $ecotone->runConsoleCommand('ecotone:projection:rebuild', ['name' => 'rebuild_progress']);
        $ecotone->run('rebuild_progress_channel', ExecutionPollingMetadata::createWithTestingSetup());
    }

    /**
     * @return list<array{int, int, int}>
     */
    private function batchProgressOf(InMemorySpanRecorder $recorder): array
    {
        $progress = [];
        foreach ($recorder->recordedSpans() as $span) {
            if (isset($span->attributes[self::BATCH_NUMBER])) {
                $progress[(int) $span->attributes[self::BATCH_NUMBER]] = [(int) $span->attributes[self::BATCH_NUMBER], (int) $span->attributes[self::BATCH_COUNT], (int) $span->attributes[self::PARTITION_COUNT]];
            }
        }
        ksort($progress);

        return array_values($progress);
    }

    private function bootstrapPartitionedRebuild(InMemorySpanRecorder $recorder, TracingConfiguration $tracing): FlowTestSupport
    {
        $projection = new #[Projection('rebuild_progress'), Partitioned, ProjectionRebuild(partitionBatchSize: 2, asyncChannelName: 'rebuild_progress_channel'), FromAggregateStream(Ticket::class)] class ($this->getConnection()) {
            public function __construct(private Connection $connection)
            {
            }

            #[EventHandler]
            public function addTicket(TicketWasRegistered $event): void
            {
                $this->connection->executeStatement('INSERT INTO rebuild_progress_tickets VALUES (?,?)', [$event->getTicketId(), $event->getTicketType()]);
            }

            #[ProjectionInitialization]
            public function initialization(): void
            {
                $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS rebuild_progress_tickets (ticket_id VARCHAR(36) PRIMARY KEY, ticket_type VARCHAR(25))');
            }

            #[ProjectionDelete]
            public function delete(): void
            {
                $this->connection->executeStatement('DROP TABLE IF EXISTS rebuild_progress_tickets');
            }

            #[ProjectionReset]
            public function reset(#[PartitionAggregateId] string $aggregateId): void
            {
                $this->connection->executeStatement('DELETE FROM rebuild_progress_tickets WHERE ticket_id = ?', [$aggregateId]);
            }
        };

        return $this->bootstrapFlowTesting(
            classesToResolve: [$projection::class, Ticket::class, TicketEventConverter::class],
            containerOrAvailableServices: [$projection, new TicketEventConverter(), self::getConnectionFactory(), InMemorySpanRecorder::class => $recorder],
            extensionObjects: [DbalConfiguration::createForTesting(), SimpleMessageChannelBuilder::createQueueChannel('rebuild_progress_channel'), $tracing],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
