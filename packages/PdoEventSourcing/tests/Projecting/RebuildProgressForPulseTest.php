<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting;

use Doctrine\DBAL\Connection;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\Tracing\FlowTrackingConfiguration;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Projecting\FromAggregateStream;
use Ecotone\Api\Projecting\PartitionAggregateId;
use Ecotone\Api\Projecting\Partitioned;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Projecting\ProjectionDelete;
use Ecotone\Api\Projecting\ProjectionInitialization;
use Ecotone\Api\Projecting\ProjectionRebuild;
use Ecotone\Api\Projecting\ProjectionReset;
use Ecotone\Api\Pulse\PulseConfiguration;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;

/**
 * @internal
 */
final class RebuildProgressForPulseTest extends ProjectingTestCase
{
    private const TRACE = '0af7651916cd43dd8448eb211c80319c';

    private object $pulse;
    private FlowTestSupport $ticketService;
    private FlowTestSupport $pulseService;

    public function test_a_partitioned_rebuild_reports_batches_done_out_of_the_batch_count_until_it_is_done(): void
    {
        $this->bootstrap();
        for ($i = 1; $i <= 5; $i++) {
            $this->ticketService->sendCommand(new RegisterTicket((string) $i, "User{$i}", "type{$i}"));
        }

        $this->rebuild('rebuild_progress_for_pulse');

        self::assertSame(['running', 0, null], $this->progress());
        $this->ticketService->run('rebuild_pulse_channel', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1));
        self::assertSame(['running', 1, 3], $this->progress());
        $this->ticketService->run('rebuild_pulse_channel', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1));
        self::assertSame(['running', 2, 3], $this->progress());
        $this->ticketService->run('rebuild_pulse_channel', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1));
        self::assertSame(['done', 3, 3], $this->progress());
    }

    private function rebuild(string $projection): void
    {
        $this->pulseService->getDistributedBus()->sendMessage('ticket_service', 'ecotone.pulse.projections.rebuild', json_encode(['projection' => $projection], JSON_THROW_ON_ERROR), MediaType::APPLICATION_JSON, metadata: [MessageHeaders::TRACING_ID => self::TRACE], replyRoutingKey: 'ecotone.pulse.snapshot_received');
        $this->ticketService->run('ticket_channel', ExecutionPollingMetadata::createWithTestingSetup());
    }

    /**
     * @return array{string, int, int|null}
     */
    private function progress(): array
    {
        $queryId = 'query-' . (count($this->pulse->queryResults) + 1);
        $this->pulseService->getDistributedBus()->sendMessage('ticket_service', 'ecotone.pulse.trackings.list', json_encode(['since' => '2000-01-01T00:00:00+00:00'], JSON_THROW_ON_ERROR), MediaType::APPLICATION_JSON, metadata: ['ecotone_pulse_query_id' => $queryId], replyRoutingKey: 'ecotone.pulse.query_result_received');
        $this->ticketService->run('ticket_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $this->pulseService->run('pulse_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $tracking = json_decode($this->pulse->queryResults[$queryId], true, flags: JSON_THROW_ON_ERROR)['trackings'][0];

        return [$tracking['state'], $tracking['batchesDone'], $tracking['batchCount']];
    }

    private function bootstrap(): void
    {
        $projection = new #[Projection('rebuild_progress_for_pulse'), Partitioned, ProjectionRebuild(partitionBatchSize: 2, asyncChannelName: 'rebuild_pulse_channel'), FromAggregateStream(Ticket::class)] class ($this->getConnection()) {
            public function __construct(private Connection $connection)
            {
            }

            #[EventHandler]
            public function addTicket(TicketWasRegistered $event): void
            {
                $this->connection->executeStatement('INSERT INTO rebuild_pulse_tickets VALUES (?,?)', [$event->getTicketId(), $event->getTicketType()]);
            }

            #[ProjectionInitialization]
            public function initialization(): void
            {
                $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS rebuild_pulse_tickets (ticket_id VARCHAR(36) PRIMARY KEY, ticket_type VARCHAR(25))');
            }

            #[ProjectionDelete]
            public function delete(): void
            {
                $this->connection->executeStatement('DROP TABLE IF EXISTS rebuild_pulse_tickets');
            }

            #[ProjectionReset]
            public function reset(#[PartitionAggregateId] string $aggregateId): void
            {
                $this->connection->executeStatement('DELETE FROM rebuild_pulse_tickets WHERE ticket_id = ?', [$aggregateId]);
            }
        };
        $this->pulse = new class () {
            public array $queryResults = [];

            #[InternalHandler('ecotone.pulse.snapshot_received')]
            public function received(string $snapshot): void
            {
            }

            #[InternalHandler('ecotone.pulse.query_result_received')]
            public function queryResult(string $result, #[Header('ecotone_pulse_query_id')] string $queryId): void
            {
                $this->queryResults[$queryId] = $result;
            }
        };
        $channels = [
            SimpleMessageChannelBuilder::createQueueChannel('ticket_channel'),
            SimpleMessageChannelBuilder::createQueueChannel('pulse_channel'),
            SimpleMessageChannelBuilder::createQueueChannel('rebuild_pulse_channel'),
        ];
        $this->ticketService = $this->bootstrapFlowTesting(
            classesToResolve: [$projection::class, Ticket::class, TicketEventConverter::class],
            containerOrAvailableServices: [$projection, new TicketEventConverter(), self::getConnectionFactory()],
            extensionObjects: [
                ...$channels,
                DbalConfiguration::createForTesting(),
                FlowTrackingConfiguration::createWithDefaults(),
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ecotone_pulse', channelName: 'pulse_channel'),
                ServiceConfiguration::createWithDefaults()->withServiceName('ticket_service'),
                PulseConfiguration::createWithDefaults('ecotone_pulse'),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $this->pulseService = EcotoneLite::bootstrapFlowTesting(
            [$this->pulse::class],
            [$this->pulse],
            [
                ...$channels,
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ticket_service', channelName: 'ticket_channel'),
                ServiceConfiguration::createWithDefaults()->withServiceName('ecotone_pulse'),
            ],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
