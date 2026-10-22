<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Pulse;

use DateTimeImmutable;
use DateTimeZone;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\Tracing\FlowTrackingConfiguration;
use Ecotone\Api\EventSourcing\Event;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Projecting\FromStream;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Projecting\Partitioned;
use Ecotone\Api\Projecting\Streaming;
use Ecotone\Api\Projecting\ProjectionDeployment;
use Ecotone\Api\Projecting\ProjectionBackfill;
use Ecotone\Api\Pulse\PulseConfiguration;
use Ecotone\Api\Scheduling\Duration;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 *
 * @internal
 */
final class PulseProjectionInitializationTest extends DbalMessagingTestCase
{
    private const FIRST_TRACE = '0af7651916cd43dd8448eb211c80319c';
    private const SECOND_TRACE = '1af7651916cd43dd8448eb211c80319c';

    private object $pulse;
    private FlowTestSupport $orderService;
    private FlowTestSupport $pulseService;

    public static function implementations(): iterable
    {
        yield 'in-memory' => ['in-memory'];
        yield 'dbal' => ['dbal'];
    }

    #[DataProvider('implementations')]
    public function test_initializing_a_projection_waiting_for_a_kick_off_records_one_tracking_and_reports_it_initialized(string $implementation): void
    {
        $this->bootstrap($implementation);
        self::assertSame('uninitialized', $this->initializationOf('kickoff_orders'));

        $reply = $this->act('ecotone.pulse.projections.initialize', 'kickoff_orders', self::FIRST_TRACE);

        self::assertSame(['tracingId' => self::FIRST_TRACE, 'projection' => 'kickoff_orders'], $reply['initializeAction']);
        self::assertSame('initialized', $this->initializationOf('kickoff_orders'));
        $trackings = $this->trackings()['trackings'];
        self::assertCount(1, $trackings);
        self::assertSame(['projection-initialization', 'kickoff_orders', 'ecotone-pulse', null, 'done'], [$trackings[0]['type'], $trackings[0]['reference'], $trackings[0]['category'], $trackings[0]['tenant'], $trackings[0]['state']]);
        self::assertSame(2, $this->orderService->sendQueryWithRouting('kickoff_orders.count'));
    }

    #[DataProvider('implementations')]
    public function test_initializing_an_unknown_projection_is_refused_with_the_known_projections(string $implementation): void
    {
        $this->bootstrap($implementation);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Pulse asked to initialize projection missing, but there is no such projection. Registered projections: [async_backfill_orders, kickoff_orders, partitioned_orders]');

        $this->act('ecotone.pulse.projections.initialize', 'missing', self::FIRST_TRACE);
    }

    #[DataProvider('implementations')]
    public function test_initializing_an_initialized_projection_is_refused_pointing_to_rebuild_and_backfill(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->act('ecotone.pulse.projections.initialize', 'kickoff_orders', self::FIRST_TRACE);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Pulse asked to initialize projection kickoff_orders, but it is already initialized, so there is nothing left to set up. Use Rebuild to run it again from the first event, or Backfill to project the events it has not seen.');

        $this->act('ecotone.pulse.projections.initialize', 'kickoff_orders', self::SECOND_TRACE);
    }

    #[DataProvider('implementations')]
    public function test_initializing_a_partitioned_projection_is_refused_because_each_partition_initializes_itself(string $implementation): void
    {
        $this->bootstrap($implementation);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Pulse asked to initialize projection partitioned_orders, but it is partitioned and every partition initializes itself on its first event, so there is no single initialization to run. Use Rebuild to run it again from the first event, or Backfill to project the events it has not seen.');

        $this->act('ecotone.pulse.projections.initialize', 'partitioned_orders', self::FIRST_TRACE);
    }

    #[DataProvider('implementations')]
    public function test_a_backfill_through_the_backfill_channel_is_running_until_its_batch_is_handled(string $implementation): void
    {
        $this->bootstrap($implementation);

        $reply = $this->act('ecotone.pulse.projections.backfill', 'async_backfill_orders', self::FIRST_TRACE);

        self::assertSame(['tracingId' => self::FIRST_TRACE, 'projection' => 'async_backfill_orders', 'mode' => 'asynchronous'], $reply['backfillAction']);
        $running = $this->trackings()['trackings'][0];
        self::assertSame(['projection-backfill', 'async_backfill_orders', 'running', 0], [$running['type'], $running['reference'], $running['state'], $running['batchesDone']]);

        $this->orderService->advanceTimeBy(Duration::seconds(5));
        $this->orderService->run('backfill_channel', ExecutionPollingMetadata::createWithTestingSetup());

        $done = $this->trackings()['trackings'][0];
        self::assertSame(['done', 1, 1, 5000], [$done['state'], $done['batchesDone'], $done['batchCount'], $done['durationMs']]);
        self::assertSame(2, $this->orderService->sendQueryWithRouting('async_backfill_orders.count'));
    }

    #[DataProvider('implementations')]
    public function test_a_backfill_without_a_backfill_channel_runs_inside_the_request_and_is_done_when_the_reply_arrives(string $implementation): void
    {
        $this->bootstrap($implementation);

        $reply = $this->act('ecotone.pulse.projections.backfill', 'kickoff_orders', self::FIRST_TRACE);

        self::assertSame('synchronous', $reply['backfillAction']['mode']);
        $tracking = $this->trackings()['trackings'][0];
        self::assertSame(['projection-backfill', 'done'], [$tracking['type'], $tracking['state']]);
        self::assertSame(2, $this->orderService->sendQueryWithRouting('kickoff_orders.count'));
    }

    #[DataProvider('implementations')]
    public function test_backfilling_an_unknown_projection_is_refused_with_the_known_projections(string $implementation): void
    {
        $this->bootstrap($implementation);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Pulse asked to backfill projection missing, but there is no such projection. Registered projections: [async_backfill_orders, kickoff_orders, partitioned_orders]');

        $this->act('ecotone.pulse.projections.backfill', 'missing', self::FIRST_TRACE);
    }

    #[DataProvider('implementations')]
    public function test_backfilling_a_streaming_projection_is_refused_because_it_keeps_no_position(string $implementation): void
    {
        $this->bootstrap($implementation);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Pulse asked to backfill projection streaming_orders, but there is no such projection. Registered projections: [async_backfill_orders, kickoff_orders, partitioned_orders]. A streaming projection is not among them: it keeps no position and reads no stored events, so it cannot be initialized, backfilled or rebuilt from Pulse. Publish the missing events to its channel again.');

        $this->act('ecotone.pulse.projections.backfill', 'streaming_orders', self::FIRST_TRACE);
    }

    private function initializationOf(string $projection): ?string
    {
        $snapshot = $this->snapshot();

        return array_values(array_filter($snapshot['projections'], fn (array $one): bool => $one['name'] === $projection))[0]['initialization'];
    }

    private function snapshot(): array
    {
        $this->pulseService->getDistributedBus()->sendMessage('order_service', 'ecotone.pulse.snapshot', '{}', MediaType::APPLICATION_JSON, replyRoutingKey: 'ecotone.pulse.snapshot_received');
        $this->orderService->run('order_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $this->pulseService->run('pulse_channel', ExecutionPollingMetadata::createWithTestingSetup());

        return json_decode(array_pop($this->pulse->snapshots), true, flags: JSON_THROW_ON_ERROR);
    }

    private function act(string $routingKey, string $projection, string $tracingId): array
    {
        $this->pulseService->getDistributedBus()->sendMessage('order_service', $routingKey, json_encode(['projection' => $projection], JSON_THROW_ON_ERROR), MediaType::APPLICATION_JSON, metadata: [MessageHeaders::TRACING_ID => $tracingId], replyRoutingKey: 'ecotone.pulse.snapshot_received');
        $this->orderService->run('order_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $this->pulseService->run('pulse_channel', ExecutionPollingMetadata::createWithTestingSetup());

        return json_decode(array_pop($this->pulse->snapshots), true, flags: JSON_THROW_ON_ERROR);
    }

    private function trackings(array $query = []): array
    {
        $queryId = 'query-' . (count($this->pulse->queryResults) + 1);
        $this->pulseService->getDistributedBus()->sendMessage('order_service', 'ecotone.pulse.trackings.list', json_encode((object) ['since' => '2026-10-13T12:00:00+00:00', ...$query], JSON_THROW_ON_ERROR), MediaType::APPLICATION_JSON, metadata: ['ecotone_pulse_query_id' => $queryId], replyRoutingKey: 'ecotone.pulse.query_result_received');
        $this->orderService->run('order_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $this->pulseService->run('pulse_channel', ExecutionPollingMetadata::createWithTestingSetup());

        return json_decode($this->pulse->queryResults[$queryId], true, flags: JSON_THROW_ON_ERROR);
    }

    private function bootstrap(string $implementation): void
    {
        $inMemory = $implementation === 'in-memory';
        $kickoffOrders = new #[Projection('kickoff_orders'), FromStream('orders_stream'), ProjectionDeployment(manualKickOff: true)] class () {
            public int $count = 0;

            #[EventHandler('order.placed')]
            public function whenPlaced(array $event): void
            {
                $this->count++;
            }

            #[QueryHandler('kickoff_orders.count')]
            public function count(): int
            {
                return $this->count;
            }
        };
        $asyncBackfillOrders = new #[Projection('async_backfill_orders'), FromStream('orders_stream'), ProjectionBackfill(asyncChannelName: 'backfill_channel')] class () {
            public int $count = 0;

            #[EventHandler('order.placed')]
            public function whenPlaced(array $event): void
            {
                $this->count++;
            }

            #[QueryHandler('async_backfill_orders.count')]
            public function count(): int
            {
                return $this->count;
            }
        };
        $streamingOrders = new #[Projection('streaming_orders'), Streaming('orders_feed')] class () {
            #[EventHandler('order.placed')]
            public function whenPlaced(array $event): void
            {
            }
        };
        $partitionedOrders = new #[Projection('partitioned_orders'), FromStream('orders_stream'), Partitioned('orderId')] class () {
            #[EventHandler('order.placed')]
            public function whenPlaced(array $event): void
            {
            }
        };
        $this->pulse = new class () {
            public array $snapshots = [];
            public array $queryResults = [];

            #[InternalHandler('ecotone.pulse.snapshot_received')]
            public function received(string $snapshot): void
            {
                $this->snapshots[] = $snapshot;
            }

            #[InternalHandler('ecotone.pulse.query_result_received')]
            public function queryResult(string $result, #[Header('ecotone_pulse_query_id')] string $queryId): void
            {
                $this->queryResults[$queryId] = $result;
            }
        };
        $channels = [
            SimpleMessageChannelBuilder::createQueueChannel('order_channel'),
            SimpleMessageChannelBuilder::createQueueChannel('pulse_channel'),
            SimpleMessageChannelBuilder::createQueueChannel('backfill_channel'),
            SimpleMessageChannelBuilder::createQueueChannel('orders_feed'),
        ];
        $this->orderService = $this->bootstrapFlowTesting(
            classesToResolve: [$kickoffOrders::class, $asyncBackfillOrders::class, $partitionedOrders::class, $streamingOrders::class],
            containerOrAvailableServices: [$kickoffOrders, $asyncBackfillOrders, $partitionedOrders, $streamingOrders, DbalConnectionFactory::class => $this->getConnectionFactory()],
            extensionObjects: [
                ...$channels,
                FlowTrackingConfiguration::createWithDefaults()->withInMemoryStorage($inMemory),
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ecotone_pulse', channelName: 'pulse_channel'),
                ServiceConfiguration::createWithDefaults()->withServiceName('order_service')->withDefaultErrorChannel('dbal_dead_letter'),
                PulseConfiguration::createWithDefaults('ecotone_pulse'),
                DbalConfiguration::createWithDefaults()->withDeadLetter(true, inMemory: $inMemory),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
            testConfiguration: TestConfiguration::createWithDefaults()->withTimeAt(new DateTimeImmutable('2026-10-20 12:00:00', new DateTimeZone('UTC'))),
        );
        $this->orderService->withEventStream('orders_stream', [Event::createWithType('order.placed', ['orderId' => '1']), Event::createWithType('order.placed', ['orderId' => '2'])]);
        $this->pulseService = $this->bootstrapFlowTesting(
            classesToResolve: [$this->pulse::class],
            containerOrAvailableServices: [$this->pulse],
            extensionObjects: [
                ...$channels,
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'order_service', channelName: 'order_channel'),
                ServiceConfiguration::createWithDefaults()->withServiceName('ecotone_pulse'),
            ],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
