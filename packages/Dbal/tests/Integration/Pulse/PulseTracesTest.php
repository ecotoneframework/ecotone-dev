<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Pulse;

use DateTimeImmutable;
use DateTimeZone;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\Tracing\FlowTrackingConfiguration;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Pulse\PulseConfiguration;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Scheduling\Duration;
use Ecotone\Api\Tracing\SpanContext;
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
final class PulseTracesTest extends DbalMessagingTestCase
{
    private const FIRST_TRACE = '0af7651916cd43dd8448eb211c80319c';
    private const SECOND_TRACE = '1af7651916cd43dd8448eb211c80319c';
    private const THIRD_TRACE = '2af7651916cd43dd8448eb211c80319c';
    private const CALLER_SPAN = 'b7ad6b7169203331';

    private array $channels;
    private object $pulse;
    private object $orders;
    private FlowTestSupport $orderService;
    private FlowTestSupport $pulseService;

    public static function implementations(): iterable
    {
        yield 'in-memory' => ['in-memory'];
        yield 'dbal' => ['dbal'];
    }

    #[DataProvider('implementations')]
    public function test_the_snapshot_reports_every_flow_type_with_its_runs_failures_and_entry_durations(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->orderService->sendCommandWithRouting('create.ticket', '100');
        $this->orderService->advanceTimeBy(Duration::seconds(10));
        $this->orderService->sendCommandWithRouting('create.ticket', '200');
        $this->orderService->advanceTimeBy(Duration::seconds(10));
        $this->orderService->sendCommandWithRouting('create.ticket', '300');
        $this->orderService->advanceTimeBy(Duration::seconds(10));
        $this->orderService->sendCommandWithRouting('refund.ticket', 'ticket-1');
        $this->orderService->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());

        $tracing = $this->requestSnapshot()['tracing'];

        self::assertSame($implementation === 'dbal' ? 'dbal' : 'in_memory', $tracing['storage']);
        self::assertSame(604800, $tracing['retention']);
        self::assertSame(
            [
                ['flowType' => 'create.ticket', 'runs' => 3, 'failedRuns' => 0, 'p50Ms' => 200, 'p95Ms' => 300, 'maxMs' => 300, 'lastStartedAt' => '2026-10-20T12:00:20.300000+00:00'],
                ['flowType' => 'refund.ticket', 'runs' => 1, 'failedRuns' => 1, 'p50Ms' => 0, 'p95Ms' => 0, 'maxMs' => 0, 'lastStartedAt' => '2026-10-20T12:00:30.600000+00:00'],
            ],
            $tracing['flowTypes'],
        );
    }

    #[DataProvider('implementations')]
    public function test_the_snapshot_has_no_tracing_section_without_flow_tracking(string $implementation): void
    {
        $this->bootstrap($implementation, flowTracking: false);

        self::assertArrayNotHasKey('tracing', $this->requestSnapshot());
    }


    #[DataProvider('implementations')]
    public function test_the_trace_list_of_a_flow_type_is_paged_newest_first(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->runCreateTicketFlows();

        $firstPage = $this->listTraces(['flowType' => 'create.ticket', 'page' => 1, 'perPage' => 2]);
        $secondPage = $this->listTraces(['flowType' => 'create.ticket', 'page' => 2, 'perPage' => 2]);

        self::assertSame(
            [
                'queryId' => 'query-1',
                'flowType' => 'create.ticket',
                'page' => 1,
                'perPage' => 2,
                'total' => 3,
                'traces' => [
                    ['traceId' => self::THIRD_TRACE, 'startedAt' => '2026-10-20T12:00:20.300000+00:00', 'durationMs' => 300, 'status' => 'ok', 'rootEndpoint' => 'ticket.create'],
                    ['traceId' => self::SECOND_TRACE, 'startedAt' => '2026-10-20T12:00:10.100000+00:00', 'durationMs' => 200, 'status' => 'ok', 'rootEndpoint' => 'ticket.create'],
                ],
            ],
            $firstPage,
        );
        self::assertSame(2, $secondPage['page']);
        self::assertSame(3, $secondPage['total']);
        self::assertSame(
            [['traceId' => self::FIRST_TRACE, 'startedAt' => '2026-10-20T12:00:00.000000+00:00', 'durationMs' => 100, 'status' => 'ok', 'rootEndpoint' => 'ticket.create']],
            $secondPage['traces'],
        );
    }

    #[DataProvider('implementations')]
    public function test_the_trace_list_defaults_to_the_first_page_of_twenty_five(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->runCreateTicketFlows();

        $list = $this->listTraces(['flowType' => 'create.ticket']);

        self::assertSame(1, $list['page']);
        self::assertSame(25, $list['perPage']);
        self::assertCount(3, $list['traces']);
    }

    #[DataProvider('implementations')]
    public function test_an_unknown_flow_type_lists_no_traces_instead_of_being_refused(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->runCreateTicketFlows();

        $list = $this->listTraces(['flowType' => 'cancel.ticket']);

        self::assertSame(0, $list['total']);
        self::assertSame([], $list['traces']);
    }

    #[DataProvider('implementations')]
    public function test_a_flow_with_a_failed_step_is_listed_as_an_error(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->sendWithTrace('refund.ticket', 'ticket-1', self::FIRST_TRACE);

        $traces = $this->listTraces(['flowType' => 'refund.ticket'])['traces'];

        self::assertSame([['traceId' => self::FIRST_TRACE, 'startedAt' => '2026-10-20T12:00:00.000000+00:00', 'durationMs' => 0, 'status' => 'error', 'rootEndpoint' => 'ticket.refund']], $traces);
    }

    #[DataProvider('implementations')]
    public function test_a_trace_replies_with_its_spans_including_the_asynchronous_step(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->sendWithTrace('create.ticket', '100', self::FIRST_TRACE);
        $this->orderService->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());

        $trace = $this->traceOf(self::FIRST_TRACE);

        self::assertSame('query-1', $trace['queryId']);
        self::assertSame(self::FIRST_TRACE, $trace['traceId']);
        $spans = $trace['spans'];
        self::assertSame(
            ['send command', 'process class@anonymous::create', 'send event', 'route ticket.notify', 'send async_channel', 'receive async_channel', 'process class@anonymous::notify'],
            array_column($spans, 'name'),
        );
        $bySpanId = array_column($spans, null, 'spanId');
        $received = $spans[5];
        self::assertSame('consumer', $received['kind']);
        self::assertSame('send async_channel', $bySpanId[$received['parentSpanId']]['name']);
        self::assertSame('2026-10-20T12:00:00.000000+00:00', $spans[0]['startedAt']);
        self::assertSame('2026-10-20T12:00:00.100000+00:00', $spans[0]['endedAt']);
        self::assertSame('create.ticket', $spans[0]['attributes']['messaging.destination.name']);
        self::assertSame('ok', $spans[0]['status']);
        self::assertNull($spans[0]['statusDescription']);
    }

    #[DataProvider('implementations')]
    public function test_a_trace_replies_with_the_failed_step_and_its_events(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->sendWithTrace('refund.ticket', 'ticket-1', self::FIRST_TRACE);

        $spans = $this->traceOf(self::FIRST_TRACE)['spans'];

        self::assertSame(
            ['send command', 'process class@anonymous::refund', 'send command', 'process class@anonymous::reverse'],
            array_column($spans, 'name'),
        );
        $failed = $spans[3];
        self::assertSame('error', $failed['status']);
        self::assertSame('Payment of ticket-1 could not be reversed', $failed['statusDescription']);
        self::assertSame('payment.reversal', $failed['attributes']['ecotone.endpoint.id']);
        self::assertSame('exception', $failed['events'][0]['name']);
        self::assertSame(RuntimeException::class, $failed['events'][0]['attributes']['exception.type']);
        self::assertSame('2026-10-20T12:00:00.000000+00:00', $failed['events'][0]['at']);
    }

    #[DataProvider('implementations')]
    public function test_a_trace_bounds_every_large_value_to_four_kilobytes(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->sendWithTrace('refund.ticket', str_repeat('é', 5000), self::FIRST_TRACE);

        $failed = $this->traceOf(self::FIRST_TRACE)['spans'][3];

        self::assertLessThanOrEqual(4096, strlen($failed['statusDescription']));
        self::assertStringEndsWith("\n... [truncated]", $failed['statusDescription']);
        self::assertTrue(mb_check_encoding($failed['statusDescription'], 'UTF-8'));
        foreach ($failed['events'][0]['attributes'] as $value) {
            self::assertLessThanOrEqual(4096, strlen((string) $value));
        }
    }

    #[DataProvider('implementations')]
    public function test_an_unknown_trace_replies_with_no_spans(string $implementation): void
    {
        $this->bootstrap($implementation);

        self::assertSame([], $this->traceOf(self::FIRST_TRACE)['spans']);
    }

    #[DataProvider('implementations')]
    public function test_a_trace_list_that_is_not_a_json_object_is_refused(string $implementation): void
    {
        $this->bootstrap($implementation);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A Pulse trace list query payload must be a JSON object such as {"flowType": "create.ticket", "page": 1, "perPage": 25}, but `nope` was sent.');
        $this->ask('ecotone.pulse.traces.list', 'nope');
    }

    #[DataProvider('implementations')]
    public function test_a_trace_list_without_a_flow_type_is_refused(string $implementation): void
    {
        $this->bootstrap($implementation);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A Pulse trace list query needs a `flowType`, but the payload had none. Send {"flowType": "create.ticket", "page": 1, "perPage": 25}, taking the flow type from `tracing.flowTypes` of the Pulse snapshot.');
        $this->listTraces([]);
    }

    #[DataProvider('implementations')]
    public function test_a_page_below_one_is_refused(string $implementation): void
    {
        $this->bootstrap($implementation);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The `page` of a Pulse trace list query is `0`, but pages start at 1. Send {"flowType": "create.ticket", "page": 1, "perPage": 25}.');
        $this->listTraces(['flowType' => 'create.ticket', 'page' => 0]);
    }

    #[DataProvider('implementations')]
    public function test_more_than_twenty_five_traces_per_page_is_refused(string $implementation): void
    {
        $this->bootstrap($implementation);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The `perPage` of a Pulse trace list query is `26`, but it must be a number from 1 to 25. Send a smaller `perPage`, or ask for the next `page`.');
        $this->listTraces(['flowType' => 'create.ticket', 'perPage' => 26]);
    }

    #[DataProvider('implementations')]
    public function test_a_trace_query_without_a_trace_id_is_refused(string $implementation): void
    {
        $this->bootstrap($implementation);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A Pulse trace query needs a `traceId`, but the payload had none. Send {"traceId": "0af7651916cd43dd8448eb211c80319c"}, taking the trace id from a trace list or from the trace id an application logged.');
        $this->ask('ecotone.pulse.traces.trace', '{}');
    }

    #[DataProvider('implementations')]
    public function test_a_trace_query_without_a_query_id_is_refused(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->pulseService->getDistributedBus()->sendMessage('order_service', 'ecotone.pulse.traces.trace', '{"traceId": "' . self::FIRST_TRACE . '"}', MediaType::APPLICATION_JSON, replyRoutingKey: 'ecotone.pulse.query_result_received');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Service order_service was asked by Pulse for traces without the query id in header `ecotone_pulse_query_id`, so Pulse could not match the result to its question. Send the request with metadata: ['ecotone_pulse_query_id' => '<unique id>'] on DistributedBus::sendMessage().");
        $this->orderService->run('order_channel', ExecutionPollingMetadata::createWithTestingSetup());
    }

    #[DataProvider('implementations')]
    public function test_a_trace_query_from_a_service_other_than_the_pulse_service_is_refused(string $implementation): void
    {
        $this->bootstrap($implementation);
        $intruder = $this->bootstrapFlowTesting(
            extensionObjects: [
                ...$this->channels,
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'order_service', channelName: 'order_channel'),
                ServiceConfiguration::createWithDefaults()->withServiceName('intruder'),
            ],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $intruder->getDistributedBus()->sendMessage('order_service', 'ecotone.pulse.traces.trace', '{"traceId": "' . self::FIRST_TRACE . '"}', MediaType::APPLICATION_JSON, metadata: ['ecotone_pulse_query_id' => 'query-1'], replyRoutingKey: 'ecotone.pulse.query_result_received');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('only answers the configured Pulse service ecotone_pulse');
        $this->orderService->run('order_channel', ExecutionPollingMetadata::createWithTestingSetup());
    }

    #[DataProvider('implementations')]
    public function test_a_trace_query_without_flow_tracking_is_refused_naming_how_to_enable_it(string $implementation): void
    {
        $this->bootstrap($implementation, flowTracking: false);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Service order_service was asked by Pulse for traces, but flow tracking is not configured there, so it recorded none. Enable it with FlowTrackingConfiguration::createWithDefaults() in a #[ServiceContext] of order_service, or stop sending trace queries to this service.');
        $this->listTraces(['flowType' => 'create.ticket']);
    }

    private function bootstrap(string $implementation, bool $flowTracking = true): void
    {
        $this->orders = new class () {
            #[CommandHandler('create.ticket', endpointId: 'ticket.create')]
            public function create(string $milliseconds, EventBus $eventBus, #[Reference] EcotoneClockInterface $clock): void
            {
                $clock->sleep(Duration::milliseconds((int) $milliseconds));
                $eventBus->publishWithRouting('ticket.created', $milliseconds);
            }

            #[Asynchronous('async_channel')]
            #[EventHandler('ticket.created', endpointId: 'ticket.notify')]
            public function notify(string $milliseconds): void
            {
            }

            #[CommandHandler('refund.ticket', endpointId: 'ticket.refund')]
            public function refund(string $ticketId, CommandBus $commandBus): void
            {
                try {
                    $commandBus->sendWithRouting('payment.reverse', $ticketId);
                } catch (RuntimeException) {
                }
            }

            #[CommandHandler('payment.reverse', endpointId: 'payment.reversal')]
            public function reverse(string $ticketId): void
            {
                throw new RuntimeException("Payment of {$ticketId} could not be reversed");
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
        $this->channels = $channels = [
            SimpleMessageChannelBuilder::createQueueChannel('async_channel'),
            SimpleMessageChannelBuilder::createQueueChannel('order_channel'),
            SimpleMessageChannelBuilder::createQueueChannel('pulse_channel'),
        ];
        $this->orderService = $this->bootstrapFlowTesting(
            classesToResolve: [$this->orders::class],
            containerOrAvailableServices: [$this->orders, DbalConnectionFactory::class => $this->getConnectionFactory()],
            extensionObjects: [
                ...$channels,
                ...($flowTracking ? [FlowTrackingConfiguration::createWithDefaults()->withInMemoryStorage($implementation === 'in-memory')] : []),
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ecotone_pulse', channelName: 'pulse_channel'),
                ServiceConfiguration::createWithDefaults()->withServiceName('order_service'),
                PulseConfiguration::createWithDefaults('ecotone_pulse'),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
            testConfiguration: TestConfiguration::createWithDefaults()->withTimeAt(new DateTimeImmutable('2026-10-20 12:00:00', new DateTimeZone('UTC'))),
        );
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

    private function runCreateTicketFlows(): void
    {
        $this->sendWithTrace('create.ticket', '100', self::FIRST_TRACE);
        $this->orderService->advanceTimeBy(Duration::seconds(10));
        $this->sendWithTrace('create.ticket', '200', self::SECOND_TRACE);
        $this->orderService->advanceTimeBy(Duration::seconds(10));
        $this->sendWithTrace('create.ticket', '300', self::THIRD_TRACE);
    }

    private function sendWithTrace(string $routingKey, string $payload, string $traceId): void
    {
        $this->orderService->sendCommandWithRouting($routingKey, $payload, metadata: [MessageHeaders::TRACEPARENT => (new SpanContext($traceId, self::CALLER_SPAN))->toTraceparent()]);
    }

    private function listTraces(array $query): array
    {
        return $this->ask('ecotone.pulse.traces.list', json_encode((object) $query, JSON_THROW_ON_ERROR));
    }

    private function traceOf(string $traceId): array
    {
        return $this->ask('ecotone.pulse.traces.trace', json_encode(['traceId' => $traceId], JSON_THROW_ON_ERROR));
    }

    private function ask(string $routingKey, string $payload): array
    {
        $queryId = 'query-' . (count($this->pulse->queryResults) + 1);
        $this->pulseService->getDistributedBus()->sendMessage('order_service', $routingKey, $payload, MediaType::APPLICATION_JSON, metadata: ['ecotone_pulse_query_id' => $queryId], replyRoutingKey: 'ecotone.pulse.query_result_received');
        $this->orderService->run('order_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $this->pulseService->run('pulse_channel', ExecutionPollingMetadata::createWithTestingSetup());
        self::assertArrayHasKey($queryId, $this->pulse->queryResults);

        return json_decode($this->pulse->queryResults[$queryId], true, flags: JSON_THROW_ON_ERROR);
    }

    private function requestSnapshot(): array
    {
        $this->pulseService->getDistributedBus()->sendMessage('order_service', 'ecotone.pulse.snapshot', '{}', MediaType::APPLICATION_JSON, replyRoutingKey: 'ecotone.pulse.snapshot_received');
        $this->orderService->run('order_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $this->pulseService->run('pulse_channel', ExecutionPollingMetadata::createWithTestingSetup());

        return json_decode(array_pop($this->pulse->snapshots), true, flags: JSON_THROW_ON_ERROR);
    }
}
