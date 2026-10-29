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
use Ecotone\Api\Projecting\ProjectionRebuild;
use Ecotone\Api\Pulse\PulseConfiguration;
use Ecotone\Api\Scheduling\Duration;
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
final class PulseRebuildTrackingTest extends DbalMessagingTestCase
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
    public function test_a_rebuild_through_the_projection_rebuild_channel_is_running_until_its_batch_is_handled(string $implementation): void
    {
        $this->bootstrap($implementation);

        $reply = $this->rebuild('async_orders', self::FIRST_TRACE);

        self::assertSame(['tracingId' => self::FIRST_TRACE, 'projection' => 'async_orders', 'mode' => 'asynchronous'], $reply['rebuildAction']);
        $running = $this->trackings()['trackings'];
        self::assertCount(1, $running);
        self::assertSame(
            ['tracingId' => self::FIRST_TRACE, 'category' => 'ecotone-pulse', 'type' => 'projection-rebuild', 'reference' => 'async_orders', 'tenant' => null, 'startedAt' => '2026-10-20T12:00:00.000000+00:00', 'state' => 'running', 'batchesDone' => 0, 'batchCount' => null, 'elapsedMs' => 0],
            $running[0],
        );

        $this->orderService->advanceTimeBy(Duration::seconds(5));
        $this->orderService->run('rebuild_channel', ExecutionPollingMetadata::createWithTestingSetup());

        $done = $this->trackings()['trackings'][0];
        self::assertSame('done', $done['state']);
        self::assertSame(1, $done['batchesDone']);
        self::assertSame(1, $done['batchCount']);
        self::assertSame(5000, $done['durationMs']);
        self::assertSame(2, $this->orderService->sendQueryWithRouting('async_orders.count'));
    }

    #[DataProvider('implementations')]
    public function test_a_rebuild_without_a_rebuild_channel_runs_inside_the_request_and_is_done_when_the_reply_arrives(string $implementation): void
    {
        $this->bootstrap($implementation);

        $reply = $this->rebuild('sync_orders', self::FIRST_TRACE);

        self::assertSame('synchronous', $reply['rebuildAction']['mode']);
        $tracking = $this->trackings()['trackings'][0];
        self::assertSame('done', $tracking['state']);
        self::assertSame('sync_orders', $tracking['reference']);
        self::assertSame(2, $this->orderService->sendQueryWithRouting('sync_orders.count'));
    }

    #[DataProvider('implementations')]
    public function test_a_rebuild_that_ends_in_the_dead_letter_is_failed_by_the_trace_of_its_tracking(string $implementation): void
    {
        $this->bootstrap($implementation);

        $this->rebuild('failing_orders', self::FIRST_TRACE);
        $this->orderService->run('failing_channel', ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false));

        $tracking = $this->trackings()['trackings'][0];
        self::assertSame('failed', $tracking['state']);
        self::assertNotNull($tracking['deadLetterMessageId']);
    }

    #[DataProvider('implementations')]
    public function test_a_rebuild_of_an_unknown_projection_is_refused_with_the_known_projections(string $implementation): void
    {
        $this->bootstrap($implementation);

        $reply = $this->rebuild('missing', self::FIRST_TRACE);

        self::assertStringContainsString('there is no such projection. Registered projections: [async_orders, failing_orders, sync_orders]', $reply['message']);
    }

    #[DataProvider('implementations')]
    public function test_trackings_are_listed_newest_first_and_narrowed_by_reference_and_since(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->rebuild('async_orders', self::FIRST_TRACE);
        $this->orderService->advanceTimeBy(Duration::seconds(7200));
        $this->rebuild('sync_orders', self::SECOND_TRACE);

        self::assertSame(['sync_orders', 'async_orders'], array_column($this->trackings()['trackings'], 'reference'));
        self::assertSame(['async_orders'], array_column($this->trackings(['reference' => 'async_orders'])['trackings'], 'reference'));
        self::assertSame(['sync_orders'], array_column($this->trackings(['since' => '2026-10-20T13:00:00+00:00'])['trackings'], 'reference'));
        self::assertSame([], $this->trackings(['category' => 'other'])['trackings']);
    }

    #[DataProvider('implementations')]
    public function test_listing_is_capped_and_says_so(string $implementation): void
    {
        $this->bootstrap($implementation);
        for ($rebuild = 0; $rebuild < 3; $rebuild++) {
            $this->rebuild('sync_orders', sprintf('%032x', $rebuild + 1));
        }

        $listing = $this->trackings(['limit' => 2]);

        self::assertCount(2, $listing['trackings']);
        self::assertTrue($listing['truncated']);
        self::assertSame(100, $listing['cap']);
    }

    public function test_a_rebuild_without_the_tracking_table_is_refused_with_the_command_that_creates_it(): void
    {
        $this->bootstrap('dbal', tablesSetUp: false);
        $this->orderService->runConsoleCommand('ecotone:migration:database:setup', ['initialize' => true, 'feature' => ['tracing', 'dead_letter']]);
        $this->orderService->initializeProjection('sync_orders');

        $reply = $this->rebuild('sync_orders', self::FIRST_TRACE);

        self::assertStringContainsString("table required by the 'pulse_tracking' feature does not exist", $reply['message']);
    }

    private function rebuild(string $projection, string $tracingId): array
    {
        $this->pulseService->getDistributedBus()->sendMessage('order_service', 'ecotone.pulse.projections.rebuild', json_encode(['projection' => $projection], JSON_THROW_ON_ERROR), MediaType::APPLICATION_JSON, metadata: [MessageHeaders::TRACING_ID => $tracingId], replyRoutingKey: 'ecotone.pulse.snapshot_received');
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

    private function bootstrap(string $implementation, bool $tablesSetUp = true): void
    {
        $inMemory = $implementation === 'in-memory';
        $asyncOrders = new #[Projection('async_orders'), FromStream('orders_stream'), ProjectionRebuild(asyncChannelName: 'rebuild_channel')] class () {
            public int $count = 0;

            #[EventHandler('order.placed')]
            public function whenPlaced(array $event): void
            {
                $this->count++;
            }

            #[QueryHandler('async_orders.count')]
            public function count(): int
            {
                return $this->count;
            }
        };
        $syncOrders = new #[Projection('sync_orders'), FromStream('orders_stream')] class () {
            public int $count = 0;

            #[EventHandler('order.placed')]
            public function whenPlaced(array $event): void
            {
                $this->count++;
            }

            #[QueryHandler('sync_orders.count')]
            public function count(): int
            {
                return $this->count;
            }
        };
        $failingOrders = new #[Projection('failing_orders'), FromStream('orders_stream'), ProjectionRebuild(asyncChannelName: 'failing_channel')] class () {
            #[EventHandler('order.placed')]
            public function whenPlaced(array $event): void
            {
                throw new RuntimeException('Order could not be projected');
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
            SimpleMessageChannelBuilder::createQueueChannel('rebuild_channel'),
            SimpleMessageChannelBuilder::createQueueChannel('failing_channel'),
        ];
        $this->orderService = $this->bootstrapFlowTesting(
            classesToResolve: [$asyncOrders::class, $syncOrders::class, $failingOrders::class],
            containerOrAvailableServices: [$asyncOrders, $syncOrders, $failingOrders, DbalConnectionFactory::class => $this->getConnectionFactory()],
            extensionObjects: [
                ...$channels,
                FlowTrackingConfiguration::createWithDefaults()->withInMemoryStorage($inMemory),
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ecotone_pulse', channelName: 'pulse_channel'),
                ServiceConfiguration::createWithDefaults()->withServiceName('order_service')->withDefaultErrorChannel('dbal_dead_letter'),
                PulseConfiguration::createWithDefaults('ecotone_pulse'),
                DbalConfiguration::createWithDefaults()->withDeadLetter(true, inMemory: $inMemory)->withDeduplication($tablesSetUp),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
            testConfiguration: TestConfiguration::createWithDefaults()->withTimeAt(new DateTimeImmutable('2026-10-20 12:00:00', new DateTimeZone('UTC')))->withDatabaseTablesSetUpOnBootstrap($tablesSetUp)->withDefaultInstantRetries(false),
        );
        $this->orderService->withEventStream('orders_stream', [Event::createWithType('order.placed', ['orderId' => '1']), Event::createWithType('order.placed', ['orderId' => '2'])]);
        if ($tablesSetUp) {
            $this->orderService->initializeProjection('async_orders')->initializeProjection('sync_orders')->initializeProjection('failing_orders');
        }
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
            testConfiguration: TestConfiguration::createWithDefaults()->withDefaultInstantRetries(false),
        );
    }
}
