<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Pulse;

use DateTimeImmutable;
use DateTimeZone;
use Ecotone\Api\Attribute\Around;
use Ecotone\Api\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\Dbal\Tracing\FlowTrackingConfiguration;
use Ecotone\Api\EventSourcing\Event;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Gateway\GatheredGroups;
use Ecotone\Api\Interceptor\MethodInvocation;
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
    private const THIRD_TRACE = '2af7651916cd43dd8448eb211c80319c';

    private object $pulse;
    private object $failingOrders;
    private object $recordingFailure;
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
            ['tracingId' => self::FIRST_TRACE, 'category' => 'ecotone-pulse', 'type' => 'projection-rebuild', 'reference' => 'async_orders', 'tenant' => null, 'startedAt' => '2026-10-20T12:00:00.000000+00:00', 'startedFrom' => 'pulse', 'state' => 'running', 'batchesDone' => 0, 'batchCount' => 1, 'missingBatches' => [1], 'failedBatches' => [], 'batchesWaitingToBeRecorded' => [], 'elapsedMs' => 0],
            array_diff_key($running[0], ['groupId' => true]),
        );
        self::assertSame($running[0]['groupId'], $this->orderService->getGateway(GatheredGroups::class)->listOpenGroups('async_orders.rebuild')[0]['groupId']);

        $this->orderService->advanceTimeBy(Duration::seconds(5));
        $this->orderService->run('rebuild_channel', ExecutionPollingMetadata::createWithTestingSetup());

        $done = $this->trackings()['trackings'][0];
        self::assertSame(['done', 1, 1, [], 5000], [$done['state'], $done['batchesDone'], $done['batchCount'], $done['missingBatches'], $done['durationMs']]);
        self::assertSame(2, $this->orderService->sendQueryWithRouting('async_orders.count'));
    }

    public function test_rebuild_progress_counts_executed_batches_without_stored_traces(): void
    {
        $this->bootstrap('dbal', traces: false);

        $this->rebuild('async_orders', self::FIRST_TRACE);
        self::assertSame(['running', 0, 1], $this->progressOf($this->trackings()['trackings'][0]));

        $this->orderService->run('rebuild_channel', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(['done', 1, 1], $this->progressOf($this->trackings()['trackings'][0]));
    }

    #[DataProvider('implementations')]
    public function test_a_rebuild_started_from_the_console_is_listed_from_its_group(string $implementation): void
    {
        $this->bootstrap($implementation);

        $this->orderService->runConsoleCommand('ecotone:projection:rebuild', ['name' => 'async_orders']);

        $running = $this->trackings()['trackings'];
        self::assertCount(1, $running);
        self::assertSame(['projection-rebuild', 'async_orders', 'console', 'running', 0, 1, [1]], [$running[0]['type'], $running[0]['reference'], $running[0]['startedFrom'], $running[0]['state'], $running[0]['batchesDone'], $running[0]['batchCount'], $running[0]['missingBatches']]);

        $this->orderService->run('rebuild_channel', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(['done', 1, 1], $this->progressOf($this->trackings()['trackings'][0]));
    }

    #[DataProvider('implementations')]
    public function test_the_group_of_a_rebuild_is_listed_in_the_gathering_snapshot_under_its_endpoint_id(string $implementation): void
    {
        $this->bootstrap($implementation);

        $reply = $this->rebuild('async_orders', self::FIRST_TRACE);

        $groups = array_values(array_filter($reply['gathering']['groups'], static fn (array $group): bool => $group['endpointId'] === 'async_orders.rebuild'));
        self::assertSame([[self::FIRST_TRACE, 'open', 1, 0, [1]]], array_map(static fn (array $group): array => [$group['groupKey'], $group['state'], $group['expectedSize'], $group['recorded'], $group['missingPartNumbers']], $groups));
    }

    #[DataProvider('implementations')]
    public function test_a_rebuild_replies_with_only_the_projections_and_the_gathering_it_changed(string $implementation): void
    {
        $this->bootstrap($implementation);

        $reply = $this->rebuild('async_orders', self::FIRST_TRACE);

        self::assertSame('async_orders', $reply['rebuildAction']['projection']);
        self::assertContains('async_orders', array_column($reply['projections'], 'name'));
        self::assertEqualsCanonicalizing(['projections', 'gathering'], array_keys($reply['digests']));
        self::assertTrue($reply['partial']);
        self::assertSame([], array_intersect(['flowGraph', 'packages', 'consumers', 'overview', 'topology', 'channels', 'tracing', 'setup', 'deadLetters'], array_keys($reply)));
    }

    #[DataProvider('implementations')]
    public function test_a_synchronous_rebuild_started_from_the_console_is_not_listed(string $implementation): void
    {
        $this->bootstrap($implementation);

        $this->orderService->runConsoleCommand('ecotone:projection:rebuild', ['name' => 'sync_orders']);

        self::assertSame([], $this->trackings()['trackings']);
    }

    #[DataProvider('implementations')]
    public function test_a_rebuild_without_a_rebuild_channel_runs_inside_the_request_and_is_done_when_the_reply_arrives(string $implementation): void
    {
        $this->bootstrap($implementation);

        $reply = $this->rebuild('sync_orders', self::FIRST_TRACE);

        self::assertSame('synchronous', $reply['rebuildAction']['mode']);
        $tracking = $this->trackings()['trackings'][0];
        self::assertSame(['done', 'sync_orders', 'pulse'], [$tracking['state'], $tracking['reference'], $tracking['startedFrom']]);
        self::assertSame(2, $this->orderService->sendQueryWithRouting('sync_orders.count'));
    }

    #[DataProvider('implementations')]
    public function test_a_rebuild_batch_in_the_dead_letter_is_failed_and_links_its_dead_letter(string $implementation): void
    {
        $this->bootstrap($implementation);

        $this->rebuild('failing_orders', self::FIRST_TRACE);
        $this->orderService->run('failing_channel', ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false));

        $tracking = $this->trackings()['trackings'][0];
        self::assertSame(['failed', 0, 1, [1]], [$tracking['state'], $tracking['batchesDone'], $tracking['batchCount'], $tracking['missingBatches']]);
        self::assertNotNull($tracking['deadLetterMessageId']);
        self::assertSame([['batch' => 1, 'deadLetterMessageId' => $tracking['deadLetterMessageId']]], $tracking['failedBatches']);
    }

    #[DataProvider('implementations')]
    public function test_a_replayed_rebuild_batch_completes_the_tracked_rebuild(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->rebuild('failing_orders', self::FIRST_TRACE);
        $this->orderService->run('failing_channel', ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false));

        $this->failingOrders->fail = false;
        $this->orderService->getGateway(DeadLetterGateway::class)->replayAll();
        $this->orderService->run('failing_channel', ExecutionPollingMetadata::createWithTestingSetup());

        $tracking = $this->trackings()['trackings'][0];
        self::assertSame(['done', 1, 1, [], []], [$tracking['state'], $tracking['batchesDone'], $tracking['batchCount'], $tracking['missingBatches'], $tracking['failedBatches']]);
    }

    #[DataProvider('implementations')]
    public function test_a_discarded_rebuild_is_not_shown_as_done(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->rebuild('failing_orders', self::FIRST_TRACE);
        $this->orderService->run('failing_channel', ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false));

        $this->orderService->runConsoleCommand('ecotone:gathering:discard', ['groupId' => $this->trackings()['trackings'][0]['groupId']]);

        $tracking = $this->trackings()['trackings'][0];
        self::assertSame(['discarded', 0, 1, [1]], [$tracking['state'], $tracking['batchesDone'], $tracking['batchCount'], $tracking['missingBatches']]);
    }

    #[DataProvider('implementations')]
    public function test_a_rebuild_released_by_hand_with_batches_missing_is_not_shown_as_done(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->rebuild('failing_orders', self::FIRST_TRACE);
        $this->orderService->run('failing_channel', ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false));

        $this->orderService->getGateway(GatheredGroups::class)->requestRelease($this->trackings()['trackings'][0]['groupId']);
        $this->orderService->run('failing_channel', ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false));

        $tracking = $this->trackings()['trackings'][0];
        self::assertSame(['released', 0, 1, [1]], [$tracking['state'], $tracking['batchesDone'], $tracking['batchCount'], $tracking['missingBatches']]);
    }

    #[DataProvider('implementations')]
    public function test_a_batch_whose_record_waits_in_the_dead_letter_is_executed_and_not_failed(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->rebuild('recorded_orders', self::FIRST_TRACE);
        $this->orderService->run('recorded_rebuild_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $this->recordingFailure->fail = true;
        $this->orderService->run('recording_channel', ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false));

        $tracking = $this->trackings()['trackings'][0];
        self::assertSame(['running', 0, 1, [1], []], [$tracking['state'], $tracking['batchesDone'], $tracking['batchCount'], $tracking['missingBatches'], $tracking['failedBatches']]);
        self::assertSame(1, $tracking['batchesWaitingToBeRecorded'][0]['batch']);
        $this->recordingFailure->fail = false;
        $this->orderService->getGateway(DeadLetterGateway::class)->replay($tracking['batchesWaitingToBeRecorded'][0]['deadLetterMessageId']);
        $this->orderService->run('recording_channel', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(['done', 1, 1, []], [$this->trackings()['trackings'][0]['state'], $this->trackings()['trackings'][0]['batchesDone'], $this->trackings()['trackings'][0]['batchCount'], $this->trackings()['trackings'][0]['batchesWaitingToBeRecorded']]);
    }

    #[DataProvider('implementations')]
    public function test_a_pulse_started_rebuild_beyond_the_listing_limit_is_not_listed_as_started_from_the_console(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->rebuild('async_orders', self::FIRST_TRACE);
        $this->orderService->advanceTimeBy(Duration::seconds(60));
        $this->rebuild('async_orders', self::SECOND_TRACE);
        $this->orderService->advanceTimeBy(Duration::seconds(60));
        $this->rebuild('async_orders', self::THIRD_TRACE);

        $listing = $this->trackings(['limit' => 1]);

        self::assertSame([[self::THIRD_TRACE, 'pulse']], array_map(static fn (array $tracking): array => [$tracking['tracingId'], $tracking['startedFrom']], $listing['trackings']));
        self::assertTrue($listing['truncated']);
    }

    public function test_a_rebuild_whose_group_was_removed_by_retention_is_done(): void
    {
        $this->bootstrap('dbal');
        $this->rebuild('async_orders', self::FIRST_TRACE);
        $this->orderService->run('rebuild_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $this->orderService->advanceTimeBy(Duration::seconds(8 * 24 * 3600));

        $this->orderService->runConsoleCommand('ecotone:gathering:cleanup', []);

        $tracking = $this->trackings()['trackings'][0];
        self::assertSame(['done', 0, null, []], [$tracking['state'], $tracking['batchesDone'], $tracking['batchCount'], $tracking['missingBatches']]);
    }

    #[DataProvider('implementations')]
    public function test_a_failed_synchronous_rebuild_leaves_no_tracking(string $implementation): void
    {
        $this->bootstrap($implementation);

        $reply = $this->rebuild('failing_sync_orders', self::FIRST_TRACE);

        self::assertStringContainsString('Order could not be projected', $reply['message']);
        self::assertSame([], $this->trackings()['trackings']);
    }

    #[DataProvider('implementations')]
    public function test_a_rebuild_of_an_unknown_projection_is_refused_with_the_known_projections(string $implementation): void
    {
        $this->bootstrap($implementation);

        $reply = $this->rebuild('missing', self::FIRST_TRACE);

        self::assertStringContainsString('there is no such projection. Registered projections: [async_orders, failing_orders, failing_sync_orders, recorded_orders, sync_orders]', $reply['message']);
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

    /**
     * @return array{string, int, int|null}
     */
    private function progressOf(array $tracking): array
    {
        return [$tracking['state'], $tracking['batchesDone'], $tracking['batchCount']];
    }

    private function trackings(array $query = []): array
    {
        $queryId = 'query-' . (count($this->pulse->queryResults) + 1);
        $this->pulseService->getDistributedBus()->sendMessage('order_service', 'ecotone.pulse.trackings.list', json_encode((object) ['since' => '2026-10-13T12:00:00+00:00', ...$query], JSON_THROW_ON_ERROR), MediaType::APPLICATION_JSON, metadata: ['ecotone_pulse_query_id' => $queryId], replyRoutingKey: 'ecotone.pulse.query_result_received');
        $this->orderService->run('order_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $this->pulseService->run('pulse_channel', ExecutionPollingMetadata::createWithTestingSetup());

        return json_decode($this->pulse->queryResults[$queryId], true, flags: JSON_THROW_ON_ERROR);
    }

    private function bootstrap(string $implementation, bool $tablesSetUp = true, bool $traces = true): void
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
            public bool $fail = true;

            #[EventHandler('order.placed')]
            public function whenPlaced(array $event): void
            {
                if ($this->fail) {
                    throw new RuntimeException('Order could not be projected');
                }
            }
        };
        $this->failingOrders = $failingOrders;
        $recordedOrders = new #[Projection('recorded_orders'), FromStream('orders_stream'), ProjectionRebuild(asyncChannelName: 'recorded_rebuild_channel', batchCompletionChannelName: 'recording_channel')] class () {
            #[EventHandler('order.placed')]
            public function whenPlaced(array $event): void
            {
            }
        };
        $this->recordingFailure = new class () {
            public bool $fail = false;

            #[Around(pointcut: AsynchronousRunningEndpoint::class)]
            public function failRecording(MethodInvocation $invocation, #[Header(MessageHeaders::POLLED_CHANNEL_NAME)] ?string $channel = null): mixed
            {
                if ($this->fail && $channel === 'recording_channel') {
                    throw new RuntimeException('Recording is down');
                }

                return $invocation->proceed();
            }
        };
        $failingSyncOrders = new #[Projection('failing_sync_orders'), FromStream('orders_stream')] class () {
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
            SimpleMessageChannelBuilder::createQueueChannel('recorded_rebuild_channel'),
            SimpleMessageChannelBuilder::createQueueChannel('recording_channel'),
        ];
        $this->orderService = $this->bootstrapFlowTesting(
            classesToResolve: [$asyncOrders::class, $syncOrders::class, $failingOrders::class, $failingSyncOrders::class, $recordedOrders::class, $this->recordingFailure::class],
            containerOrAvailableServices: [$asyncOrders, $syncOrders, $failingOrders, $failingSyncOrders, $recordedOrders, $this->recordingFailure, DbalConnectionFactory::class => $this->getConnectionFactory()],
            extensionObjects: [
                ...$channels,
                ...($traces ? [FlowTrackingConfiguration::createWithDefaults()->withInMemoryStorage($inMemory)] : []),
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
            $this->orderService->initializeProjection('async_orders')->initializeProjection('sync_orders')->initializeProjection('failing_orders')->initializeProjection('failing_sync_orders')->initializeProjection('recorded_orders');
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
