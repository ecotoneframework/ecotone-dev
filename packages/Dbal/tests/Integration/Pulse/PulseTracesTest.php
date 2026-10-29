<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Pulse;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Distributed;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\Dbal\Tracing\FlowTrackingConfiguration;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\DistributedBus;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Pulse\PulseConfiguration;
use Ecotone\Api\Scheduling\Duration;
use Ecotone\Api\Tracing\SpanContext;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Test\LicenceTesting;
use Interop\Queue\ConnectionFactory;
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

    private const UPGRADE_INSTRUCTIONS = <<<'TEXT'
        The 'ecotone_tracing' table required by the 'tracing' feature has no 'flow_type' and 'flow_status' columns, which flow tracking writes and Ecotone Pulse reads since the 2.0 release. The table was created by an earlier 2.0 beta. Ecotone never alters a table while it handles a message.

        Add the columns with the statement for your database:
          PostgreSQL: ALTER TABLE ecotone_tracing ADD COLUMN flow_type VARCHAR(255) DEFAULT NULL; ALTER TABLE ecotone_tracing ADD COLUMN flow_status VARCHAR(16) DEFAULT NULL; CREATE INDEX ecotone_tracing_flow_type_started_at_idx ON ecotone_tracing (flow_type, started_at);
          MySQL and MariaDB: ALTER TABLE ecotone_tracing ADD COLUMN flow_type VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL, ADD COLUMN flow_status VARCHAR(16) DEFAULT NULL, ADD INDEX ecotone_tracing_flow_type_started_at_idx (flow_type, started_at);
          SQLite: ALTER TABLE ecotone_tracing ADD COLUMN flow_type VARCHAR(255) DEFAULT NULL; ALTER TABLE ecotone_tracing ADD COLUMN flow_status VARCHAR(16) DEFAULT NULL; CREATE INDEX ecotone_tracing_flow_type_started_at_idx ON ecotone_tracing (flow_type, started_at);

        Or, as tracked flows are only kept for the configured retention, drop the 'ecotone_tracing' table and create it again with $messagingSystem->getServiceFromContainer(\Ecotone\Api\Dbal\ExtensionObject\DatabaseSetupManager::class)->initialize('tracing').
        TEXT;

    private array $channels;
    private object $pulse;
    private object $orders;
    private FlowTestSupport $orderService;
    private FlowTestSupport $pulseService;
    private FlowTestSupport $ticketService;
    private FlowTestSupport $auditService;

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
        self::assertSame(1000, $tracing['statsWindow']);
        self::assertSame(
            [
                ['flowType' => 'create.ticket', 'runs' => 3, 'windowRuns' => 3, 'runsWithErrors' => 0, 'p50Ms' => 200, 'p95Ms' => 300, 'maxMs' => 300, 'lastStartedAt' => '2026-10-20T12:00:20.300000+00:00'],
                ['flowType' => 'refund.ticket', 'runs' => 1, 'windowRuns' => 1, 'runsWithErrors' => 1, 'p50Ms' => 0, 'p95Ms' => 0, 'maxMs' => 0, 'lastStartedAt' => '2026-10-20T12:00:30.600000+00:00'],
            ],
            $tracing['flowTypes'],
        );
    }

    #[DataProvider('implementations')]
    public function test_pulse_requests_never_become_flow_types(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->sendWithTrace('create.ticket', '100', self::FIRST_TRACE);

        $this->requestSnapshot();
        $this->listTraces(['flowType' => 'create.ticket']);
        $this->traceOf(self::FIRST_TRACE);
        $tracing = $this->requestSnapshot()['tracing'];

        self::assertSame(['create.ticket'], array_column($tracing['flowTypes'], 'flowType'));
        self::assertSame(1, $tracing['flowTypes'][0]['runs']);
    }

    #[DataProvider('implementations')]
    public function test_a_pulse_snapshot_request_alone_records_no_flow_type(string $implementation): void
    {
        $this->bootstrap($implementation);

        self::assertSame([], $this->requestSnapshot()['tracing']['flowTypes']);
        self::assertSame([], $this->requestSnapshot()['tracing']['flowTypes']);
    }

    #[DataProvider('implementations')]
    public function test_a_refused_trace_query_is_answered_to_pulse_and_leaves_no_dead_letter(string $implementation): void
    {
        $this->bootstrap($implementation, deadLetter: true);

        $reply = $this->ask('ecotone.pulse.traces.trace', '{}');

        self::assertSame('ecotone.pulse.traces.trace', $reply['requestKind']);
        self::assertSame('query-1', $reply['queryId']);
        self::assertSame(0, $this->orderService->getGateway(DeadLetterGateway::class)->count());
    }

    #[DataProvider('implementations')]
    public function test_a_pulse_dead_letter_action_never_becomes_a_flow_type(string $implementation): void
    {
        $this->bootstrap($implementation, deadLetter: true);
        $this->sendWithTrace('create.ticket', '100', self::FIRST_TRACE);

        $this->pulseService->getDistributedBus()->sendMessage('order_service', 'ecotone.pulse.dead_letter.delete', '{"messageIds": ["unknown"]}', MediaType::APPLICATION_JSON, replyRoutingKey: 'ecotone.pulse.snapshot_received');
        $this->orderService->run('order_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $this->pulseService->run('pulse_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $tracing = $this->requestSnapshot()['tracing'];

        self::assertSame(['create.ticket'], array_column($tracing['flowTypes'], 'flowType'));
    }

    #[DataProvider('implementations')]
    public function test_a_distributed_command_continues_the_trace_in_the_receiving_service(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->bootstrapTicketService();
        $this->sendWithTrace('open.ticket.remotely', 'printer', self::FIRST_TRACE);
        $this->ticketService->run('ticket_channel', ExecutionPollingMetadata::createWithTestingSetup());

        $sending = $this->traceOf(self::FIRST_TRACE);
        $receiving = $this->traceOf(self::FIRST_TRACE, 'ticket_service');

        self::assertSame(self::FIRST_TRACE, $receiving['traceId']);
        self::assertSame(['send command', 'process class@anonymous::openRemotely', 'send distributed', 'send ticket_channel'], array_column($sending['spans'], 'name'));
        self::assertSame(['receive ticket_channel', 'send command', 'process class@anonymous::open'], array_column($receiving['spans'], 'name'));
        $sent = array_column($sending['spans'], null, 'name')['send ticket_channel'];
        self::assertSame($sent['spanId'], $receiving['spans'][0]['parentSpanId']);
        self::assertSame([self::FIRST_TRACE], array_column($this->listTraces(['flowType' => 'ticket.open'], 'ticket_service')['traces'], 'traceId'));
        self::assertSame([self::FIRST_TRACE], array_column($this->listTraces(['flowType' => 'open.ticket.remotely'])['traces'], 'traceId'));
    }

    #[DataProvider('implementations')]
    public function test_a_failure_recorded_after_the_flow_started_marks_the_flow_failed(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->sendWithTrace('create.ticket', '999', self::FIRST_TRACE);
        $this->sendWithTrace('create.ticket', '100', self::SECOND_TRACE);

        self::assertSame(
            [['flowType' => 'create.ticket', 'runs' => 2, 'runsWithErrors' => 0]],
            array_map(fn (array $flowType): array => array_intersect_key($flowType, array_flip(['flowType', 'runs', 'runsWithErrors'])), $this->requestSnapshot()['tracing']['flowTypes']),
        );

        $this->orderService->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());

        $flowType = $this->requestSnapshot()['tracing']['flowTypes'][0];
        self::assertSame(1, $flowType['runsWithErrors']);
        $statusByTrace = array_column($this->listTraces(['flowType' => 'create.ticket'])['traces'], 'status', 'traceId');
        self::assertSame(['error', 'ok'], [$statusByTrace[self::FIRST_TRACE], $statusByTrace[self::SECOND_TRACE]]);
    }

    public function test_a_spans_table_from_an_earlier_beta_is_refused_on_the_write_naming_how_to_upgrade_it(): void
    {
        $this->bootstrap('dbal', connectionFactory: $this->connectionOfABetaSpansTable());
        BetaSpansTableConnection::$tableLacksFlowColumns = true;

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(self::UPGRADE_INSTRUCTIONS);
        $this->sendWithTrace('create.ticket', '100', self::FIRST_TRACE);
    }

    public function test_a_spans_table_from_an_earlier_beta_is_refused_on_the_insert_chaining_the_database_failure(): void
    {
        $this->bootstrap('dbal', connectionFactory: $this->connectionOfABetaSpansTable());
        BetaSpansTableConnection::$tableLacksFlowColumns = true;
        BetaSpansTableConnection::$onlyInserts = true;

        try {
            $this->sendWithTrace('create.ticket', '100', self::FIRST_TRACE);
            self::fail('The write to a spans table without flow columns was not refused');
        } catch (ConfigurationException $exception) {
            self::assertSame(self::UPGRADE_INSTRUCTIONS, $exception->getMessage());
            self::assertNotNull($exception->getPrevious());
        }
    }

    public function test_a_spans_table_from_an_earlier_beta_is_reported_on_the_snapshot_naming_how_to_upgrade_it(): void
    {
        $this->bootstrap('dbal', connectionFactory: $this->connectionOfABetaSpansTable());
        $this->sendWithTrace('create.ticket', '100', self::FIRST_TRACE);
        BetaSpansTableConnection::$tableLacksFlowColumns = true;

        $snapshot = $this->requestSnapshot();

        self::assertSame(self::UPGRADE_INSTRUCTIONS, $snapshot['sectionErrors']['tracing']);
    }

    public function test_a_spans_table_from_an_earlier_beta_is_refused_on_the_trace_list_naming_how_to_upgrade_it(): void
    {
        $this->bootstrap('dbal', connectionFactory: $this->connectionOfABetaSpansTable());
        $this->sendWithTrace('create.ticket', '100', self::FIRST_TRACE);
        BetaSpansTableConnection::$tableLacksFlowColumns = true;

        $reply = $this->listTraces(['flowType' => 'create.ticket']);

        self::assertSame(self::UPGRADE_INSTRUCTIONS, $reply['message']);
    }

    #[DataProvider('implementations')]
    public function test_a_dead_lettered_message_carries_the_trace_id_its_trace_can_be_loaded_by(string $implementation): void
    {
        $this->bootstrap($implementation, deadLetter: true);
        $this->sendWithTrace('ticket.close', 'ticket-1', self::FIRST_TRACE);
        $this->orderService->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false));

        $recent = $this->requestSnapshot()['deadLetters']['recent'];

        self::assertSame([self::FIRST_TRACE], array_column($recent, 'traceId'));
        self::assertNotSame([], $this->traceOf($recent[0]['traceId'])['spans']);
    }

    #[DataProvider('implementations')]
    public function test_a_dead_lettered_message_of_a_service_without_flow_tracking_has_no_trace_id(string $implementation): void
    {
        $this->bootstrap($implementation, flowTracking: false, deadLetter: true);
        $this->orderService->sendCommandWithRouting('ticket.close', 'ticket-1');
        $this->orderService->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false));

        $recent = $this->requestSnapshot()['deadLetters']['recent'];

        self::assertCount(1, $recent);
        self::assertNull($recent[0]['traceId']);
    }

    #[DataProvider('implementations')]
    public function test_a_dead_lettered_message_sent_with_a_tracing_id_carries_that_trace_id(string $implementation): void
    {
        $this->bootstrap($implementation, deadLetter: true);
        $this->orderService->sendCommandWithRouting('ticket.close', 'ticket-1', metadata: [MessageHeaders::TRACING_ID => self::FIRST_TRACE]);
        $this->orderService->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false));

        self::assertSame([self::FIRST_TRACE], array_column($this->requestSnapshot()['deadLetters']['recent'], 'traceId'));
    }

    #[DataProvider('implementations')]
    public function test_statistics_cover_the_most_recent_thousand_runs_while_runs_counts_all(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->sendWithTrace('create.ticket', '5000', self::FIRST_TRACE);
        for ($run = 1; $run <= 1000; $run++) {
            $this->orderService->advanceTimeBy(Duration::seconds(1));
            $this->orderService->sendCommandWithRouting('create.ticket', '0');
        }

        $flowType = $this->requestSnapshot()['tracing']['flowTypes'][0];

        self::assertSame(1001, $flowType['runs']);
        self::assertSame(1000, $flowType['windowRuns']);
        self::assertSame(0, $flowType['maxMs']);
        self::assertSame(0, $flowType['p95Ms']);
    }

    #[DataProvider('implementations')]
    public function test_a_handler_that_throws_is_not_counted_as_a_run_with_errors(string $implementation): void
    {
        $this->bootstrap($implementation);
        try {
            $this->sendWithTrace('payment.reverse', 'ticket-1', self::FIRST_TRACE);
        } catch (RuntimeException) {
        }

        self::assertSame([], $this->requestSnapshot()['tracing']['flowTypes']);
    }

    #[DataProvider('implementations')]
    public function test_any_request_under_the_pulse_routing_prefix_is_kept_out_of_flow_tracking(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->pulseService->getDistributedBus()->sendCommand('order_service', 'ecotone.pulse.contributed', 'payload');
        $this->orderService->run('order_channel', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame([], $this->requestSnapshot()['tracing']['flowTypes']);
    }

    #[DataProvider('implementations')]
    public function test_a_trace_reply_holds_at_most_a_thousand_spans_parents_first_and_says_it_was_cut(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->sendWithTrace('fan.out', '600', self::FIRST_TRACE);

        $trace = $this->traceOf(self::FIRST_TRACE);

        self::assertSame(1202, $trace['totalSpans']);
        self::assertTrue($trace['truncated']);
        self::assertCount(1000, $trace['spans']);
        self::assertSame('send command', $trace['spans'][0]['name']);
        $seen = [];
        foreach ($trace['spans'] as $span) {
            self::assertTrue($span['parentSpanId'] === self::CALLER_SPAN || isset($seen[$span['parentSpanId']]));
            $seen[$span['spanId']] = true;
        }
    }

    #[DataProvider('implementations')]
    public function test_a_trace_reply_within_the_limit_is_not_flagged_as_cut(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->sendWithTrace('refund.ticket', 'ticket-1', self::FIRST_TRACE);

        $trace = $this->traceOf(self::FIRST_TRACE);

        self::assertSame(4, $trace['totalSpans']);
        self::assertFalse($trace['truncated']);
    }

    #[DataProvider('implementations')]
    public function test_a_distributed_event_is_traced_into_every_subscribing_service(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->bootstrapTicketService();
        $this->bootstrapAuditService();
        $this->sendWithTrace('announce.ticket.opened', 'printer', self::FIRST_TRACE);
        $this->ticketService->run('ticket_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $this->auditService->run('audit_channel', ExecutionPollingMetadata::createWithTestingSetup());

        $publishing = $this->traceOf(self::FIRST_TRACE)['spans'];
        $ticketing = $this->traceOf(self::FIRST_TRACE, 'ticket_service');
        $auditing = $this->traceOf(self::FIRST_TRACE, 'audit_service');

        self::assertSame(self::FIRST_TRACE, $ticketing['traceId']);
        self::assertSame(self::FIRST_TRACE, $auditing['traceId']);
        self::assertSame($this->spanIdNamed($publishing, 'send ticket_channel'), $this->entrySpanParent($ticketing['spans']));
        self::assertSame($this->spanIdNamed($publishing, 'send audit_channel'), $this->entrySpanParent($auditing['spans']));
        self::assertSame('receive ticket_channel', $ticketing['spans'][0]['name']);
        self::assertSame('receive audit_channel', $auditing['spans'][0]['name']);
        self::assertContains('process class@anonymous::whenOpened', array_column($ticketing['spans'], 'name'));
        self::assertContains('process class@anonymous::whenOpened', array_column($auditing['spans'], 'name'));
    }

    #[DataProvider('implementations')]
    public function test_a_distributed_command_handled_asynchronously_stays_in_the_trace_of_the_sender(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->bootstrapTicketService();
        $this->sendWithTrace('open.ticket.later', 'printer', self::FIRST_TRACE);
        $this->ticketService->run('ticket_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $this->ticketService->run('ticket_async', ExecutionPollingMetadata::createWithTestingSetup());

        $sending = $this->traceOf(self::FIRST_TRACE)['spans'];
        $receiving = $this->traceOf(self::FIRST_TRACE, 'ticket_service')['spans'];
        $names = array_column($receiving, 'name');

        self::assertSame($this->spanIdNamed($sending, 'send ticket_channel'), $this->entrySpanParent($receiving));
        self::assertContains('receive ticket_async', $names);
        self::assertContains('process class@anonymous::openLater', $names);
        self::assertSame($this->spanIdNamed($receiving, 'send ticket_async'), array_column($receiving, 'parentSpanId', 'name')['receive ticket_async']);
    }

    #[DataProvider('implementations')]
    public function test_a_distributed_event_handled_asynchronously_stays_in_the_trace_of_the_publisher(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->bootstrapTicketService();
        $this->sendWithTrace('announce.ticket.closed', 'printer', self::FIRST_TRACE);
        $this->ticketService->run('ticket_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $this->ticketService->run('ticket_async', ExecutionPollingMetadata::createWithTestingSetup());

        $publishing = $this->traceOf(self::FIRST_TRACE)['spans'];
        $receiving = $this->traceOf(self::FIRST_TRACE, 'ticket_service')['spans'];

        self::assertSame($this->spanIdNamed($publishing, 'send ticket_channel'), $this->entrySpanParent($receiving));
        self::assertContains('process class@anonymous::whenClosed', array_column($receiving, 'name'));
        self::assertSame($this->spanIdNamed($receiving, 'send ticket_async'), array_column($receiving, 'parentSpanId', 'name')['receive ticket_async']);
        self::assertSame([self::FIRST_TRACE], array_column($this->listTraces(['flowType' => 'ticket.closed'], 'ticket_service')['traces'], 'traceId'));
    }

    #[DataProvider('implementations')]
    public function test_flows_older_than_the_retention_disappear_from_lists_and_statistics_once_removed(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->sendWithTrace('create.ticket', '100', self::FIRST_TRACE);
        $this->orderService->advanceTimeBy(Duration::minutes(6 * 24 * 60));
        $this->sendWithTrace('create.ticket', '100', self::SECOND_TRACE);
        $this->orderService->advanceTimeBy(Duration::minutes(2 * 24 * 60));

        $this->orderService->runConsoleCommand('ecotone:tracing:remove-expired-traces');

        self::assertSame([self::SECOND_TRACE], array_column($this->listTraces(['flowType' => 'create.ticket'])['traces'], 'traceId'));
        self::assertSame(1, $this->requestSnapshot()['tracing']['flowTypes'][0]['runs']);
        self::assertSame([], $this->traceOf(self::FIRST_TRACE)['spans']);
    }

    #[DataProvider('implementations')]
    public function test_flow_types_differing_only_in_case_stay_apart(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->sendWithTrace('create.ticket', '100', self::FIRST_TRACE);
        $this->sendWithTrace('Create.Ticket', '100', self::SECOND_TRACE);
        $this->sendWithTrace('Create.Ticket', '100', self::THIRD_TRACE);

        $flowTypes = $this->requestSnapshot()['tracing']['flowTypes'];

        self::assertSame([['Create.Ticket', 2], ['create.ticket', 1]], array_map(fn (array $flowType): array => [$flowType['flowType'], $flowType['runs']], $flowTypes));
        self::assertSame([self::FIRST_TRACE], array_column($this->listTraces(['flowType' => 'create.ticket'])['traces'], 'traceId'));
    }

    #[DataProvider('implementations')]
    public function test_a_distributed_command_forwarded_through_a_second_service_nests_hop_by_hop(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->bootstrapTicketService();
        $this->bootstrapAuditService();
        $this->sendWithTrace('open.ticket.forwarded', 'printer', self::FIRST_TRACE);
        $this->ticketService->run('ticket_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $this->auditService->run('audit_channel', ExecutionPollingMetadata::createWithTestingSetup());

        $first = $this->traceOf(self::FIRST_TRACE)['spans'];
        $second = $this->traceOf(self::FIRST_TRACE, 'ticket_service')['spans'];
        $third = $this->traceOf(self::FIRST_TRACE, 'audit_service')['spans'];

        self::assertSame($this->spanIdNamed($first, 'send ticket_channel'), $this->entrySpanParent($second));
        self::assertSame($this->spanIdNamed($second, 'send audit_channel'), $this->entrySpanParent($third));
        self::assertContains('process class@anonymous::record', array_column($third, 'name'));
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

        $reply = $this->ask('ecotone.pulse.traces.list', 'nope');

        self::assertSame('A Pulse trace list query payload must be a JSON object such as {"flowType": "create.ticket", "page": 1, "perPage": 25}, but `nope` was sent.', $reply['message']);
    }

    #[DataProvider('implementations')]
    public function test_a_trace_list_without_a_flow_type_is_refused(string $implementation): void
    {
        $this->bootstrap($implementation);

        $reply = $this->listTraces([]);

        self::assertSame('A Pulse trace list query needs a `flowType`, but the payload had none. Send {"flowType": "create.ticket", "page": 1, "perPage": 25}, taking the flow type from `tracing.flowTypes` of the Pulse snapshot.', $reply['message']);
    }

    #[DataProvider('implementations')]
    public function test_a_page_below_one_is_refused(string $implementation): void
    {
        $this->bootstrap($implementation);

        $reply = $this->listTraces(['flowType' => 'create.ticket', 'page' => 0]);

        self::assertSame('The `page` of a Pulse trace list query is `0`, but pages start at 1. Send {"flowType": "create.ticket", "page": 1, "perPage": 25}.', $reply['message']);
    }

    #[DataProvider('implementations')]
    public function test_more_than_twenty_five_traces_per_page_is_refused(string $implementation): void
    {
        $this->bootstrap($implementation);

        $reply = $this->listTraces(['flowType' => 'create.ticket', 'perPage' => 26]);

        self::assertSame('The `perPage` of a Pulse trace list query is `26`, but it must be a number from 1 to 25. Send a smaller `perPage`, or ask for the next `page`.', $reply['message']);
    }

    #[DataProvider('implementations')]
    public function test_a_trace_query_without_a_trace_id_is_refused(string $implementation): void
    {
        $this->bootstrap($implementation);

        $reply = $this->ask('ecotone.pulse.traces.trace', '{}');

        self::assertSame('A Pulse trace query needs a `traceId`, but the payload had none. Send {"traceId": "0af7651916cd43dd8448eb211c80319c"}, taking the trace id from a trace list or from the trace id an application logged.', $reply['message']);
    }

    #[DataProvider('implementations')]
    public function test_a_trace_query_without_a_query_id_is_refused(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->pulseService->getDistributedBus()->sendMessage('order_service', 'ecotone.pulse.traces.trace', '{"traceId": "' . self::FIRST_TRACE . '"}', MediaType::APPLICATION_JSON, replyRoutingKey: 'ecotone.pulse.snapshot_received');
        $this->orderService->run('order_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $this->pulseService->run('pulse_channel', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(
            "Service order_service was asked by Pulse for traces without the query id in header `ecotone_pulse_query_id`, so Pulse could not match the result to its question. Send the request with metadata: ['ecotone_pulse_query_id' => '<unique id>'] on DistributedBus::sendMessage().",
            json_decode(array_pop($this->pulse->snapshots), true, flags: JSON_THROW_ON_ERROR)['message']
        );
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
            testConfiguration: TestConfiguration::createWithDefaults()->withDefaultInstantRetries(false),
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

        $reply = $this->listTraces(['flowType' => 'create.ticket']);

        self::assertSame('Service order_service was asked by Pulse for traces, but flow tracking is not configured there, so it recorded none. Enable it with FlowTrackingConfiguration::createWithDefaults() in a #[ServiceContext] of order_service, or stop sending trace queries to this service.', $reply['message']);
    }

    private function bootstrap(string $implementation, bool $flowTracking = true, bool $deadLetter = false, ?ConnectionFactory $connectionFactory = null): void
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
            public function notify(string $milliseconds, CommandBus $commandBus): void
            {
                if ($milliseconds !== '999') {
                    return;
                }

                try {
                    $commandBus->sendWithRouting('payment.reverse', $milliseconds);
                } catch (RuntimeException) {
                }
            }

            #[CommandHandler('open.ticket.remotely', endpointId: 'ticket.open_remotely')]
            public function openRemotely(string $subject, DistributedBus $distributedBus): void
            {
                $distributedBus->sendCommand('ticket_service', 'ticket.open', $subject);
            }

            #[Asynchronous('async_channel')]
            #[CommandHandler('ticket.close', endpointId: 'ticket.closing')]
            public function close(string $ticketId): void
            {
                throw new RuntimeException("Ticket {$ticketId} could not be closed");
            }

            #[Distributed]
            #[CommandHandler('ecotone.pulse.contributed', endpointId: 'pulse.contributed')]
            public function contributed(string $payload): void
            {
            }

            #[CommandHandler('announce.ticket.opened', endpointId: 'ticket.announce_opened')]
            public function announceOpened(string $subject, DistributedBus $distributedBus): void
            {
                $distributedBus->publishEvent('ticket.opened', $subject);
            }

            #[CommandHandler('announce.ticket.closed', endpointId: 'ticket.announce_closed')]
            public function announceClosed(string $subject, DistributedBus $distributedBus): void
            {
                $distributedBus->publishEvent('ticket.closed', $subject);
            }

            #[CommandHandler('open.ticket.forwarded', endpointId: 'ticket.open_forwarded')]
            public function openForwarded(string $subject, DistributedBus $distributedBus): void
            {
                $distributedBus->sendCommand('ticket_service', 'ticket.forward', $subject);
            }

            #[CommandHandler('open.ticket.later', endpointId: 'ticket.open_later')]
            public function openLater(string $subject, DistributedBus $distributedBus): void
            {
                $distributedBus->sendCommand('ticket_service', 'ticket.open.later', $subject);
            }

            #[CommandHandler('Create.Ticket', endpointId: 'ticket.create_capitalised')]
            public function createCapitalised(string $milliseconds): void
            {
            }

            #[CommandHandler('fan.out', endpointId: 'ticket.fan_out')]
            public function fanOut(string $times, CommandBus $commandBus): void
            {
                for ($step = 0; $step < (int) $times; $step++) {
                    $commandBus->sendWithRouting('fan.step', (string) $step);
                }
            }

            #[CommandHandler('fan.step', endpointId: 'ticket.fan_step')]
            public function fanStep(string $step): void
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
            SimpleMessageChannelBuilder::createQueueChannel('ticket_channel'),
            SimpleMessageChannelBuilder::createQueueChannel('audit_channel'),
            SimpleMessageChannelBuilder::createQueueChannel('ticket_async'),
        ];
        $this->orderService = $this->bootstrapFlowTesting(
            classesToResolve: [$this->orders::class],
            containerOrAvailableServices: [$this->orders, DbalConnectionFactory::class => $connectionFactory ?? $this->getConnectionFactory()],
            extensionObjects: [
                ...$channels,
                ...($flowTracking ? [FlowTrackingConfiguration::createWithDefaults()->withInMemoryStorage($implementation === 'in-memory')] : []),
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ecotone_pulse', channelName: 'pulse_channel')->withCommandMapping(targetServiceName: 'ticket_service', channelName: 'ticket_channel')->withEventMapping(channelName: 'ticket_channel', subscriptionKeys: ['ticket.*'])->withEventMapping(channelName: 'audit_channel', subscriptionKeys: ['ticket.opened']),
                ServiceConfiguration::createWithDefaults()->withServiceName('order_service')->withDefaultErrorChannel($deadLetter ? 'dbal_dead_letter' : 'errorChannel'),
                PulseConfiguration::createWithDefaults('ecotone_pulse'),
                ...($deadLetter ? [DbalConfiguration::createWithDefaults()->withDeadLetter(true, inMemory: $implementation === 'in-memory')] : []),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
            testConfiguration: TestConfiguration::createWithDefaults()->withTimeAt(new DateTimeImmutable('2026-10-20 12:00:00', new DateTimeZone('UTC')))->withDefaultInstantRetries(false),
        );
        $this->pulseService = $this->bootstrapFlowTesting(
            classesToResolve: [$this->pulse::class],
            containerOrAvailableServices: [$this->pulse],
            extensionObjects: [
                ...$channels,
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'order_service', channelName: 'order_channel')->withCommandMapping(targetServiceName: 'ticket_service', channelName: 'ticket_channel')->withCommandMapping(targetServiceName: 'audit_service', channelName: 'audit_channel'),
                ServiceConfiguration::createWithDefaults()->withServiceName('ecotone_pulse'),
            ],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
            testConfiguration: TestConfiguration::createWithDefaults()->withDefaultInstantRetries(false),
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

    private function listTraces(array $query, string $service = 'order_service'): array
    {
        return $this->ask('ecotone.pulse.traces.list', json_encode((object) $query, JSON_THROW_ON_ERROR), $service);
    }

    private function traceOf(string $traceId, string $service = 'order_service'): array
    {
        return $this->ask('ecotone.pulse.traces.trace', json_encode(['traceId' => $traceId], JSON_THROW_ON_ERROR), $service);
    }

    private function ask(string $routingKey, string $payload, string $service = 'order_service'): array
    {
        $queryId = 'query-' . (count($this->pulse->queryResults) + 1);
        $this->pulseService->getDistributedBus()->sendMessage($service, $routingKey, $payload, MediaType::APPLICATION_JSON, metadata: ['ecotone_pulse_query_id' => $queryId], replyRoutingKey: 'ecotone.pulse.query_result_received');
        match ($service) {
            'order_service' => $this->orderService->run('order_channel', ExecutionPollingMetadata::createWithTestingSetup()),
            'ticket_service' => $this->ticketService->run('ticket_channel', ExecutionPollingMetadata::createWithTestingSetup()),
            'audit_service' => $this->auditService->run('audit_channel', ExecutionPollingMetadata::createWithTestingSetup()),
        };
        $this->pulseService->run('pulse_channel', ExecutionPollingMetadata::createWithTestingSetup());
        self::assertArrayHasKey($queryId, $this->pulse->queryResults);

        return json_decode($this->pulse->queryResults[$queryId], true, flags: JSON_THROW_ON_ERROR);
    }

    private function bootstrapTicketService(): void
    {
        $tickets = new class () {
            #[Distributed]
            #[CommandHandler('ticket.open', endpointId: 'ticket.opening')]
            public function open(string $subject): void
            {
            }

            #[Distributed]
            #[CommandHandler('ticket.forward', endpointId: 'ticket.forwarding')]
            public function forward(string $subject, DistributedBus $distributedBus): void
            {
                $distributedBus->sendCommand('audit_service', 'audit.record', $subject);
            }

            #[Distributed]
            #[Asynchronous('ticket_async')]
            #[CommandHandler('ticket.open.later', endpointId: 'ticket.opening_later')]
            public function openLater(string $subject): void
            {
            }

            #[Distributed]
            #[EventHandler('ticket.opened', endpointId: 'ticket.opened_listener')]
            public function whenOpened(string $subject): void
            {
            }

            #[Distributed]
            #[Asynchronous('ticket_async')]
            #[EventHandler('ticket.closed', endpointId: 'ticket.closed_listener')]
            public function whenClosed(string $subject): void
            {
            }
        };
        $this->ticketService = $this->bootstrapFlowTesting(
            classesToResolve: [$tickets::class],
            containerOrAvailableServices: [$tickets],
            extensionObjects: [
                ...$this->channels,
                FlowTrackingConfiguration::createWithDefaults()->withInMemoryStorage(true),
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ecotone_pulse', channelName: 'pulse_channel')->withCommandMapping(targetServiceName: 'audit_service', channelName: 'audit_channel'),
                ServiceConfiguration::createWithDefaults()->withServiceName('ticket_service'),
                PulseConfiguration::createWithDefaults('ecotone_pulse'),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
            testConfiguration: TestConfiguration::createWithDefaults()->withTimeAt(new DateTimeImmutable('2026-10-20 12:00:00', new DateTimeZone('UTC')))->withDefaultInstantRetries(false),
        );
    }

    private function bootstrapAuditService(): void
    {
        $audits = new class () {
            #[Distributed]
            #[CommandHandler('audit.record', endpointId: 'audit.recording')]
            public function record(string $subject): void
            {
            }

            #[Distributed]
            #[EventHandler('ticket.opened', endpointId: 'audit.ticket_opened')]
            public function whenOpened(string $subject): void
            {
            }
        };
        $this->auditService = $this->bootstrapFlowTesting(
            classesToResolve: [$audits::class],
            containerOrAvailableServices: [$audits],
            extensionObjects: [
                ...$this->channels,
                FlowTrackingConfiguration::createWithDefaults()->withInMemoryStorage(true),
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ecotone_pulse', channelName: 'pulse_channel'),
                ServiceConfiguration::createWithDefaults()->withServiceName('audit_service'),
                PulseConfiguration::createWithDefaults('ecotone_pulse'),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
            testConfiguration: TestConfiguration::createWithDefaults()->withTimeAt(new DateTimeImmutable('2026-10-20 12:00:00', new DateTimeZone('UTC')))->withDefaultInstantRetries(false),
        );
    }

    private function entrySpanParent(array $receivingSpans): string
    {
        return $receivingSpans[0]['parentSpanId'];
    }

    private function spanIdNamed(array $spans, string $name): string
    {
        return array_column($spans, 'spanId', 'name')[$name];
    }

    private function connectionOfABetaSpansTable(): DbalConnectionFactory
    {
        BetaSpansTableConnection::$tableLacksFlowColumns = false;
        BetaSpansTableConnection::$onlyInserts = false;

        return new DbalConnectionFactory(['dsn' => getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@localhost:5432/ecotone', 'connection' => ['wrapperClass' => BetaSpansTableConnection::class]]);
    }

    private function requestSnapshot(): array
    {
        $this->pulseService->getDistributedBus()->sendMessage('order_service', 'ecotone.pulse.snapshot', '{}', MediaType::APPLICATION_JSON, replyRoutingKey: 'ecotone.pulse.snapshot_received');
        $this->orderService->run('order_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $this->pulseService->run('pulse_channel', ExecutionPollingMetadata::createWithTestingSetup());

        return json_decode(array_pop($this->pulse->snapshots), true, flags: JSON_THROW_ON_ERROR);
    }
}

final class BetaSpansTableConnection extends Connection
{
    public static bool $tableLacksFlowColumns = false;
    public static bool $onlyInserts = false;

    public function executeQuery(string $sql, array $params = [], array $types = [], ?QueryCacheProfile $qcp = null): Result
    {
        return parent::executeQuery(self::asTheBetaTableKnewIt($sql), $params, $types, $qcp);
    }

    public function executeStatement(string $sql, array $params = [], array $types = []): int|string
    {
        return parent::executeStatement(self::asTheBetaTableKnewIt($sql), $params, $types);
    }

    private static function asTheBetaTableKnewIt(string $sql): string
    {
        return self::$tableLacksFlowColumns && ! str_starts_with($sql, 'CREATE') && (! self::$onlyInserts || str_starts_with($sql, 'INSERT')) ? preg_replace('/\\bflow_type\\b/', 'flow_type_of_the_final_release', $sql) : $sql;
    }
}
