<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Conformance;

use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Ecotone\Api\Attribute\Around;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalDeadLetterBuilder;
use Ecotone\Api\Dbal\ExtensionObject\MultiTenantConfiguration;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\Dbal\Tracing\FlowCompleted;
use Ecotone\Api\Dbal\Tracing\FlowStepCompleted;
use Ecotone\Api\Dbal\Tracing\FlowTrackingConfiguration;
use Ecotone\Api\Dbal\Tracing\FlowTrackingTimedOut;
use Ecotone\Api\Dbal\Tracing\OnFlowCompleted;
use Ecotone\Api\Dbal\Tracing\OnFlowStepCompleted;
use Ecotone\Api\Dbal\Tracing\TracingGateway;
use Ecotone\Api\EventSourcing\Event;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\InstantRetryConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Interceptor\MethodInvocation;
use Ecotone\Api\Interceptor\Precedence;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Projecting\FromStream;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Scheduling\Duration;
use Ecotone\Api\Scheduling\TimeSpan;
use Ecotone\Api\Tracing\SpanContext;
use Ecotone\Api\Tracing\SpanStatus;
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
final class FlowTrackingConformanceTest extends DbalMessagingTestCase
{
    public function tearDown(): void
    {
        OutageSimulatingConnection::$databaseDown = false;
        parent::tearDown();
    }

    public static function implementations(): iterable
    {
        yield 'in-memory' => ['in-memory'];
        yield 'dbal' => ['dbal'];
    }

    #[DataProvider('implementations')]
    public function test_a_synchronous_flow_is_completed_when_the_bus_returns(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation, [$this->users()]);
        $trace = SpanContext::newTrace();

        $ecotone->sendCommandWithRouting('user.register', '1', metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);

        self::assertTrue($this->completion($ecotone)->isCompleted($trace->traceId));
        self::assertTrue($this->completion($ecotone)->isCompletedFor($trace->traceId, 'user.registration'));
    }

    #[DataProvider('implementations')]
    public function test_an_asynchronous_handler_keeps_the_trace_pending_until_its_consumer_ran(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation, [$this->users(), $this->asynchronousNotifier()], [SimpleMessageChannelBuilder::createQueueChannel('async_channel')]);
        $trace = SpanContext::newTrace();

        $ecotone->sendCommandWithRouting('user.register', '1', metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);

        self::assertFalse($this->completion($ecotone)->isCompleted($trace->traceId));
        self::assertFalse($this->completion($ecotone)->isCompletedFor($trace->traceId, 'user.notifier'));

        $ecotone->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertTrue($this->completion($ecotone)->isCompletedFor($trace->traceId, 'user.notifier'));
        self::assertTrue($this->completion($ecotone)->isCompleted($trace->traceId));
    }

    #[DataProvider('implementations')]
    public function test_awaiting_a_pending_trace_times_out_naming_what_is_still_pending(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation, [$this->users(), $this->asynchronousNotifier()], [SimpleMessageChannelBuilder::createQueueChannel('async_channel')]);
        $trace = SpanContext::newTrace();
        $ecotone->sendCommandWithRouting('user.register', '1', metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);

        $this->expectException(FlowTrackingTimedOut::class);
        $this->expectExceptionMessage('Still pending: user.notifier, async_channel. Run the consumer of the pending channel, replay the message from the dead letter');

        $this->completion($ecotone)->await($trace->traceId, Duration::milliseconds(100));
    }

    #[DataProvider('implementations')]
    public function test_a_flow_completed_subscriber_is_called_once_after_the_last_handler_committed(string $implementation): void
    {
        $subscriber = new class () {
            /** @var string[] */
            public array $completedTraces = [];

            #[OnFlowCompleted]
            public function whenCompleted(FlowCompleted $event): void
            {
                $this->completedTraces[] = $event->tracingId;
            }
        };
        $ecotone = $this->bootstrap($implementation, [$this->users(), $this->asynchronousNotifier(), $subscriber], [SimpleMessageChannelBuilder::createQueueChannel('async_channel')]);
        $trace = SpanContext::newTrace();

        $ecotone->sendCommandWithRouting('user.register', '1', metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);
        self::assertSame([], $subscriber->completedTraces);

        $ecotone->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $ecotone->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame([$trace->traceId], $subscriber->completedTraces);
    }

    #[DataProvider('implementations')]
    public function test_a_failing_asynchronous_handler_leaves_the_trace_pending(string $implementation): void
    {
        $notifier = new class () {
            #[Asynchronous('async_channel')]
            #[EventHandler('user.registered', endpointId: 'user.notifier')]
            public function notify(string $userId): void
            {
                throw new RuntimeException('Notification failed');
            }
        };
        $ecotone = $this->bootstrap($implementation, [$this->users(), $notifier], [SimpleMessageChannelBuilder::createQueueChannel('async_channel')]);
        $trace = SpanContext::newTrace();
        $ecotone->sendCommandWithRouting('user.register', '1', metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);

        try {
            $ecotone->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());
        } catch (RuntimeException) {
        }

        self::assertFalse($this->completion($ecotone)->isCompleted($trace->traceId));
    }

    #[DataProvider('implementations')]
    public function test_traces_older_than_the_retention_are_removed_by_the_console_command(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation, [$this->users()], [], Duration::minutes(10));
        $expired = SpanContext::newTrace();
        $ecotone->sendCommandWithRouting('user.register', '1', metadata: [MessageHeaders::TRACEPARENT => $expired->toTraceparent()]);

        $ecotone->advanceTimeBy(TimeSpan::withMinutes(11));
        $recent = SpanContext::newTrace();
        $ecotone->sendCommandWithRouting('user.register', '2', metadata: [MessageHeaders::TRACEPARENT => $recent->toTraceparent()]);

        $ecotone->runConsoleCommand('ecotone:tracing:remove-expired-traces');

        self::assertFalse($this->completion($ecotone)->isCompleted($expired->traceId));
        self::assertTrue($this->completion($ecotone)->isCompleted($recent->traceId));
    }

    #[DataProvider('implementations')]
    public function test_an_asynchronous_command_keeps_the_trace_pending_until_its_consumer_ran(string $implementation): void
    {
        $orders = new class () {
            #[Asynchronous('async_channel')]
            #[CommandHandler('order.place', endpointId: 'order.placing')]
            public function place(string $orderId): void
            {
            }
        };
        $ecotone = $this->bootstrap($implementation, [$orders], [SimpleMessageChannelBuilder::createQueueChannel('async_channel')]);
        $trace = SpanContext::newTrace();

        $ecotone->sendCommandWithRouting('order.place', '1', metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);

        self::assertFalse($this->completion($ecotone)->isCompleted($trace->traceId));

        $ecotone->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertTrue($this->completion($ecotone)->isCompletedFor($trace->traceId, 'order.placing'));
        self::assertTrue($this->completion($ecotone)->isCompleted($trace->traceId));
    }

    public function test_a_consumer_alone_does_not_complete_a_trace_whose_producer_stored_nothing(): void
    {
        $producer = $this->bootstrapFlowTesting(
            classesToResolve: [$this->asynchronousNotifier()::class],
            containerOrAvailableServices: [$this->asynchronousNotifier(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            extensionObjects: [DbalBackedMessageChannelBuilder::create('async_channel')],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
        )->setupDatabaseTables();
        $consumer = $this->bootstrap('dbal', [$this->asynchronousNotifier()], [DbalBackedMessageChannelBuilder::create('async_channel')]);
        $trace = SpanContext::newTrace();

        $producer->publishEventWithRouting('user.registered', '1', metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);
        $consumer->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertTrue($this->completion($consumer)->isCompletedFor($trace->traceId, 'user.notifier'));
        self::assertFalse($this->completion($consumer)->isCompleted($trace->traceId));
    }

    public function test_a_send_rolled_back_with_its_failed_handler_is_not_awaited_after_the_retry_succeeded(): void
    {
        $notifier = new class () {
            public int $attempts = 0;

            #[Asynchronous('async_channel')]
            #[EventHandler('user.registered', endpointId: 'user.notifier')]
            public function notify(string $userId, EventBus $eventBus): void
            {
                $this->attempts++;
                $eventBus->publishWithRouting('user.notified', $userId);
                if ($this->attempts === 1) {
                    throw new RuntimeException('Notification failed once');
                }
            }

            #[Asynchronous('audit_channel')]
            #[EventHandler('user.notified', endpointId: 'user.auditor')]
            public function audit(string $userId): void
            {
            }
        };
        $ecotone = $this->bootstrap('dbal', [$this->users(), $notifier], [DbalBackedMessageChannelBuilder::create('async_channel'), DbalBackedMessageChannelBuilder::create('audit_channel')]);
        $trace = SpanContext::newTrace();
        $ecotone->sendCommandWithRouting('user.register', '1', metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);

        try {
            $ecotone->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());
        } catch (RuntimeException) {
        }
        $ecotone->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $ecotone->run('audit_channel', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(2, $notifier->attempts);
        self::assertTrue($this->completion($ecotone)->isCompleted($trace->traceId));
    }

    #[DataProvider('implementations')]
    public function test_a_failing_asynchronous_handler_stores_nothing_by_default(string $implementation): void
    {
        $subscriber = $this->flowStepCompletedSubscriber();
        $ecotone = $this->bootstrap($implementation, [$this->users(), $this->failingNotifier(), $subscriber], [SimpleMessageChannelBuilder::createQueueChannel('async_channel')]);
        $ecotone->sendCommandWithRouting('user.register', '1');
        $storedBeforeTheConsumerRan = $subscriber->endedSpans;

        try {
            $ecotone->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());
        } catch (RuntimeException) {
        }

        self::assertSame($storedBeforeTheConsumerRan, $subscriber->endedSpans);
    }

    #[DataProvider('implementations')]
    public function test_a_consumer_that_swallowed_its_handler_failure_leaves_the_trace_pending(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation, [$this->users(), $this->failingNotifier()], [SimpleMessageChannelBuilder::createQueueChannel('async_channel'), ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel('errorChannel')]);
        $trace = SpanContext::newTrace();
        $ecotone->sendCommandWithRouting('user.register', '1', metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);

        $ecotone->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false));

        self::assertFalse($this->completion($ecotone)->isCompleted($trace->traceId));
    }

    #[DataProvider('implementations')]
    public function test_a_dead_lettered_message_replayed_later_completes_the_trace_it_came_from(string $implementation): void
    {
        $notifier = new class () {
            public int $attempts = 0;

            #[Asynchronous('async_channel')]
            #[EventHandler('user.registered', endpointId: 'user.notifier')]
            public function notify(string $userId): void
            {
                if (++$this->attempts === 1) {
                    throw new RuntimeException('Notification failed once');
                }
            }
        };
        $ecotone = $this->bootstrap($implementation, [$this->users(), $notifier], [
            SimpleMessageChannelBuilder::createQueueChannel('async_channel'),
            ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel(DbalDeadLetterBuilder::STORE_CHANNEL),
            InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
        ]);
        $trace = SpanContext::newTrace();
        $ecotone->sendCommandWithRouting('user.register', '1', metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);
        $ecotone->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false));
        self::assertFalse($this->completion($ecotone)->isCompleted($trace->traceId));

        $ecotone->getGateway(DeadLetterGateway::class)->replayAll();
        $ecotone->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(2, $notifier->attempts);
        self::assertTrue($this->completion($ecotone)->isCompleted($trace->traceId));
    }

    #[DataProvider('implementations')]
    public function test_a_pending_trace_older_than_the_retention_survives_until_twice_the_retention(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation, [$this->users(), $this->asynchronousNotifier()], [SimpleMessageChannelBuilder::createQueueChannel('async_channel')], Duration::minutes(10));
        $delayed = SpanContext::newTrace();
        $ecotone->sendCommandWithRouting('user.register', '1', metadata: [MessageHeaders::TRACEPARENT => $delayed->toTraceparent()]);

        $ecotone->advanceTimeBy(TimeSpan::withMinutes(11));
        $ecotone->runConsoleCommand('ecotone:tracing:remove-expired-traces');
        $ecotone->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());
        self::assertTrue($this->completion($ecotone)->isCompleted($delayed->traceId));

        $abandoned = SpanContext::newTrace();
        $ecotone->sendCommandWithRouting('user.register', '2', metadata: [MessageHeaders::TRACEPARENT => $abandoned->toTraceparent()]);
        $ecotone->advanceTimeBy(TimeSpan::withMinutes(21));
        $ecotone->runConsoleCommand('ecotone:tracing:remove-expired-traces');

        self::assertFalse($this->completion($ecotone)->isCompletedFor($abandoned->traceId, 'user.registration'));
    }

    #[DataProvider('implementations')]
    public function test_a_subscriber_that_throws_does_not_fail_the_committed_command_and_is_not_retried(string $implementation): void
    {
        $subscriber = new class () {
            /** @var string[] */
            public array $completedTraces = [];

            #[OnFlowCompleted]
            public function whenCompleted(FlowCompleted $event): void
            {
                $this->completedTraces[] = $event->tracingId;
                if (count($this->completedTraces) === 1) {
                    throw new RuntimeException('Websocket is down');
                }
            }
        };
        $ecotone = $this->bootstrap($implementation, [$this->users(), $subscriber]);
        $first = SpanContext::newTrace();
        $second = SpanContext::newTrace();

        $ecotone->sendCommandWithRouting('user.register', '1', metadata: [MessageHeaders::TRACEPARENT => $first->toTraceparent()]);
        self::assertSame([$first->traceId], $subscriber->completedTraces);

        $ecotone->sendCommandWithRouting('user.register', '2', metadata: [MessageHeaders::TRACEPARENT => $second->toTraceparent()]);

        self::assertSame([$first->traceId, $second->traceId], $subscriber->completedTraces);
    }

    #[DataProvider('implementations')]
    public function test_a_trace_that_receives_new_work_after_completing_is_notified_again_when_it_completes_again(string $implementation): void
    {
        $subscriber = new class () {
            /** @var string[] */
            public array $completedTraces = [];

            #[OnFlowCompleted]
            public function whenCompleted(FlowCompleted $event): void
            {
                $this->completedTraces[] = $event->tracingId;
            }
        };
        $orders = new class () {
            #[Asynchronous('async_channel')]
            #[CommandHandler('order.place', endpointId: 'order.placing')]
            public function place(string $orderId): void
            {
            }
        };
        $ecotone = $this->bootstrap($implementation, [$this->users(), $orders, $subscriber], [SimpleMessageChannelBuilder::createQueueChannel('async_channel')]);
        $trace = SpanContext::newTrace();

        $ecotone->sendCommandWithRouting('user.register', '1', metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);
        self::assertSame([$trace->traceId], $subscriber->completedTraces);

        $ecotone->sendCommandWithRouting('order.place', '1', metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);
        self::assertFalse($this->completion($ecotone)->isCompleted($trace->traceId));
        self::assertSame([$trace->traceId], $subscriber->completedTraces);

        $ecotone->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertTrue($this->completion($ecotone)->isCompleted($trace->traceId));
        self::assertSame([$trace->traceId, $trace->traceId], $subscriber->completedTraces);
    }

    public function test_a_channel_published_through_the_outbox_completes_once_its_consumer_ran(): void
    {
        $ecotone = $this->bootstrap('dbal', [$this->users(), $this->asynchronousNotifier()], [DbalBackedMessageChannelBuilder::create('async_channel')->withHighThroughputPublishing()]);
        $trace = SpanContext::newTrace();

        $ecotone->sendCommandWithRouting('user.register', '1', metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);
        self::assertFalse($this->completion($ecotone)->isCompleted($trace->traceId));

        $ecotone->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertTrue($this->completion($ecotone)->isCompletedFor($trace->traceId, 'user.notifier'));
        self::assertTrue($this->completion($ecotone)->isCompleted($trace->traceId));
    }

    public function test_flow_tracking_stores_each_flow_in_the_database_of_its_tenant(): void
    {
        $ecotone = $this->bootstrapMultiTenant([$this->users(), $this->asynchronousNotifier()], $this->connectionForTenantA());
        $trace = SpanContext::newTrace();

        $ecotone->sendCommandWithRouting('user.register', '1', metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent(), 'tenant' => 'tenant_a']);
        self::assertFalse($this->completion($ecotone)->isCompleted($trace->traceId, metadata: ['tenant' => 'tenant_a']));

        $ecotone->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertTrue($this->completion($ecotone)->isCompleted($trace->traceId, metadata: ['tenant' => 'tenant_a']));
        self::assertTrue($this->completion($ecotone)->isCompletedFor($trace->traceId, 'user.notifier', metadata: ['tenant' => 'tenant_a']));
        self::assertFalse($this->completion($ecotone)->isCompleted($trace->traceId, metadata: ['tenant' => 'tenant_b']));
        $this->completion($ecotone)->await($trace->traceId, Duration::milliseconds(100), metadata: ['tenant' => 'tenant_a']);
    }

    public function test_a_tenant_flow_is_written_inside_the_tenant_transaction_and_rolls_back_with_it(): void
    {
        $tenantA = new DbalConnectionFactory([
            'dsn' => getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@localhost:5432/ecotone',
            'connection' => ['wrapperClass' => CommitFailingConnection::class],
        ]);
        $ecotone = $this->bootstrapMultiTenant([$this->users()], $tenantA);
        $trace = SpanContext::newTrace();

        CommitFailingConnection::$failsCommit = true;
        try {
            $ecotone->sendCommandWithRouting('user.register', '1', metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent(), 'tenant' => 'tenant_a']);
            self::fail('The tenant commit was expected to fail');
        } catch (RuntimeException $failure) {
            self::assertSame('The commit failed', $failure->getMessage());
        } finally {
            CommitFailingConnection::$failsCommit = false;
        }

        self::assertFalse($this->completion($ecotone)->isCompleted($trace->traceId, metadata: ['tenant' => 'tenant_a']));
    }

    public function test_expired_flows_are_removed_from_the_database_of_the_tenant_the_console_command_names(): void
    {
        $ecotone = $this->bootstrapMultiTenant([$this->users()], $this->connectionForTenantA());
        $ofTenantA = SpanContext::newTrace();
        $ofTenantB = SpanContext::newTrace();
        $ecotone->sendCommandWithRouting('user.register', '1', metadata: [MessageHeaders::TRACEPARENT => $ofTenantA->toTraceparent(), 'tenant' => 'tenant_a']);
        $ecotone->sendCommandWithRouting('user.register', '2', metadata: [MessageHeaders::TRACEPARENT => $ofTenantB->toTraceparent(), 'tenant' => 'tenant_b']);
        $ecotone->advanceTimeBy(TimeSpan::withDays(8));

        $ecotone->runConsoleCommand('ecotone:tracing:remove-expired-traces', ['header' => ['tenant:tenant_a']]);

        self::assertFalse($this->completion($ecotone)->isCompleted($ofTenantA->traceId, metadata: ['tenant' => 'tenant_a']));
        self::assertTrue($this->completion($ecotone)->isCompleted($ofTenantB->traceId, metadata: ['tenant' => 'tenant_b']));
    }

    public function test_asking_the_tracing_gateway_of_a_multi_tenant_application_without_a_tenant_names_the_metadata_it_needs(): void
    {
        $ecotone = $this->bootstrapMultiTenant([$this->users()], $this->connectionForTenantA());

        $this->expectExceptionMessage("TracingGateway reads a flow from the database of the tenant it ran for, and this call names no tenant. Pass the tenant as metadata, for example isCompleted(\$tracingId, metadata: ['tenant' => 'tenant_a']).");

        $this->completion($ecotone)->isCompleted(SpanContext::newTrace()->traceId);
    }

    public function test_a_database_outage_right_after_the_consumer_committed_does_not_fail_the_consumer(): void
    {
        $notifier = new class () {
            public int $handled = 0;

            #[Asynchronous('async_channel')]
            #[EventHandler('user.registered', endpointId: 'user.notifier')]
            public function notify(string $userId): void
            {
                $this->handled++;
            }
        };
        $outage = $this->outageRightAfterTheTransaction();
        $ecotone = $this->bootstrapOnAConnectionThatCanGoDown([$this->users(), $notifier, $outage], [SimpleMessageChannelBuilder::createQueueChannel('async_channel')]);
        $ecotone->sendCommandWithRouting('user.register', '1');

        $outage->armed = true;
        $ecotone->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $ecotone->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(1, $notifier->handled);
    }

    public function test_a_database_outage_right_after_the_command_committed_does_not_fail_the_command(): void
    {
        $users = new class () {
            public int $registered = 0;

            #[CommandHandler('user.register', endpointId: 'user.registration')]
            public function register(string $userId): void
            {
                $this->registered++;
            }
        };
        $outage = $this->outageRightAfterTheTransaction();
        $ecotone = $this->bootstrapOnAConnectionThatCanGoDown([$users, $outage]);

        $outage->armed = true;
        $ecotone->sendCommandWithRouting('user.register', '1');

        self::assertSame(1, $users->registered);
    }

    public function test_a_database_outage_right_after_an_asynchronous_projection_committed_does_not_fail_its_consumer(): void
    {
        $projection = new #[Projection('users_list'), FromStream('users_stream'), Asynchronous('async_channel')] class () {
            public int $projected = 0;

            #[EventHandler('user.registered')]
            public function whenRegistered(array $event): void
            {
                $this->projected++;
            }
        };
        $outage = new class () {
            public bool $armed = false;

            #[Around(precedence: Precedence::DATABASE_TRANSACTION_PRECEDENCE + 2, pointcut: 'Ecotone\\Api\\Attribute\\AsynchronousRunningEndpoint')]
            public function goDownAfterTheProjectionRan(MethodInvocation $methodInvocation): mixed
            {
                $result = $methodInvocation->proceed();
                if ($this->armed) {
                    OutageSimulatingConnection::$databaseDown = true;
                }

                return $result;
            }
        };
        $ecotone = $this->bootstrapOnAConnectionThatCanGoDown([$projection, $outage], [SimpleMessageChannelBuilder::createQueueChannel('async_channel')]);
        $ecotone->withEvents([Event::createWithType('user.registered', ['userId' => '1'])]);
        $trace = SpanContext::newTrace();
        $ecotone->publishEventWithRouting('user.registered', [], metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);

        $outage->armed = true;
        $ecotone->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $outage->armed = false;
        OutageSimulatingConnection::$databaseDown = false;

        self::assertSame(1, $projection->projected);
        self::assertTrue($this->completion($ecotone)->isCompletedFor($trace->traceId, 'users_list'));
        self::assertTrue($this->completion($ecotone)->isCompleted($trace->traceId));
    }

    public function test_a_database_outage_inside_the_consumer_transaction_leaves_the_message_to_be_retried(): void
    {
        $notifier = new class () {
            public int $handled = 0;

            #[Asynchronous('async_channel')]
            #[EventHandler('user.registered', endpointId: 'user.notifier')]
            public function notify(string $userId): void
            {
                $this->handled++;
                OutageSimulatingConnection::$databaseDown = $this->handled === 1;
            }
        };
        $recovery = new class () {
            #[Around(precedence: Precedence::DATABASE_TRANSACTION_PRECEDENCE - 1, pointcut: 'Ecotone\\Api\\Attribute\\AsynchronousRunningEndpoint')]
            public function comeBackBeforeEachAttempt(MethodInvocation $methodInvocation): mixed
            {
                OutageSimulatingConnection::$databaseDown = false;

                return $methodInvocation->proceed();
            }
        };
        $ecotone = $this->bootstrapOnAConnectionThatCanGoDown([$this->users(), $notifier, $recovery], [SimpleMessageChannelBuilder::createQueueChannel('async_channel')]);
        $trace = SpanContext::newTrace();
        $ecotone->sendCommandWithRouting('user.register', '1', metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);

        $ecotone->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(2, $notifier->handled);
        self::assertTrue($this->completion($ecotone)->isCompleted($trace->traceId));
    }

    public function test_completion_recorded_by_one_instance_is_visible_to_another_sharing_the_database(): void
    {
        $producer = $this->bootstrap('dbal', [$this->users()]);
        $observer = $this->bootstrap('dbal', [$this->users()]);
        $trace = SpanContext::newTrace();

        $producer->sendCommandWithRouting('user.register', '1', metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);

        self::assertTrue($this->completion($observer)->isCompleted($trace->traceId));
    }

    /**
     * @param object[] $services
     * @param object[] $extensionObjects
     */
    private function bootstrap(string $implementation, array $services, array $extensionObjects = [], ?Duration $retention = null): FlowTestSupport
    {
        return $this->bootstrapFlowTesting(
            classesToResolve: array_map(fn (object $service): string => $service::class, $services),
            containerOrAvailableServices: [...$services, DbalConnectionFactory::class => $this->getConnectionFactory()],
            extensionObjects: [self::flowTracking($implementation, $retention), ...$extensionObjects],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        )->setupDatabaseTables();
    }

    private static function flowTracking(string $implementation, ?Duration $retention): FlowTrackingConfiguration
    {
        $flowTracking = FlowTrackingConfiguration::createWithDefaults()->withInMemoryStorage($implementation === 'in-memory');

        return $retention === null ? $flowTracking : $flowTracking->withRetention($retention);
    }

    private function completion(FlowTestSupport $ecotone): TracingGateway
    {
        return $ecotone->getGateway(TracingGateway::class);
    }

    private function users(): object
    {
        return new class () {
            #[CommandHandler('user.register', endpointId: 'user.registration')]
            public function register(string $userId, EventBus $eventBus): void
            {
                $eventBus->publishWithRouting('user.registered', $userId);
            }
        };
    }

    /**
     * @param object[] $services
     */
    private function bootstrapMultiTenant(array $services, DbalConnectionFactory $tenantAConnection): FlowTestSupport
    {
        $ecotone = $this->bootstrapFlowTesting(
            classesToResolve: array_map(fn (object $service): string => $service::class, $services),
            containerOrAvailableServices: [...$services, 'tenant_a_connection' => $tenantAConnection, 'tenant_b_connection' => $this->connectionForTenantB()],
            extensionObjects: [
                FlowTrackingConfiguration::createWithDefaults(),
                MultiTenantConfiguration::create('tenant', ['tenant_a' => 'tenant_a_connection', 'tenant_b' => 'tenant_b_connection']),
                SimpleMessageChannelBuilder::createQueueChannel('async_channel'),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            testConfiguration: TestConfiguration::createWithDefaults()->withDatabaseTablesSetUpOnBootstrap(false),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $ecotone->runConsoleCommand('ecotone:migration:database:delete', ['force' => true]);
        $ecotone->runConsoleCommand('ecotone:migration:database:setup', ['initialize' => true]);

        return $ecotone;
    }

    private function outageRightAfterTheTransaction(): object
    {
        return new class () {
            public bool $armed = false;

            #[Around(precedence: Precedence::DATABASE_TRANSACTION_PRECEDENCE - 1, pointcut: 'Ecotone\\Api\\Gateway\\CommandBus||Ecotone\\Api\\Attribute\\AsynchronousRunningEndpoint')]
            public function goDownAfterTheTransaction(MethodInvocation $methodInvocation): mixed
            {
                $result = $methodInvocation->proceed();
                if ($this->armed) {
                    OutageSimulatingConnection::$databaseDown = true;
                }

                return $result;
            }
        };
    }

    /**
     * @param object[] $services
     * @param object[] $extensionObjects
     */
    private function bootstrapOnAConnectionThatCanGoDown(array $services, array $extensionObjects = []): FlowTestSupport
    {
        $connectionFactory = new DbalConnectionFactory([
            'dsn' => getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@localhost:5432/ecotone',
            'connection' => ['wrapperClass' => OutageSimulatingConnection::class],
        ]);

        return $this->bootstrapFlowTesting(
            classesToResolve: array_map(fn (object $service): string => $service::class, $services),
            containerOrAvailableServices: [...$services, DbalConnectionFactory::class => $connectionFactory],
            extensionObjects: [FlowTrackingConfiguration::createWithDefaults(), ...$extensionObjects],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        )->setupDatabaseTables();
    }

    private function failingNotifier(): object
    {
        return new class () {
            #[Asynchronous('async_channel')]
            #[EventHandler('user.registered', endpointId: 'user.notifier')]
            public function notify(string $userId): void
            {
                throw new RuntimeException('Notification failed');
            }
        };
    }

    private function flowStepCompletedSubscriber(): object
    {
        return new class () {
            /** @var array<string, SpanStatus> */
            public array $endedSpans = [];

            #[OnFlowStepCompleted]
            public function whenEnded(FlowStepCompleted $event): void
            {
                $this->endedSpans[$event->step->name] = $event->step->status;
            }
        };
    }

    private function asynchronousNotifier(): object
    {
        return new class () {
            #[Asynchronous('async_channel')]
            #[EventHandler('user.registered', endpointId: 'user.notifier')]
            public function notify(string $userId): void
            {
            }
        };
    }
}

final class OutageSimulatingConnection extends Connection
{
    public static bool $databaseDown = false;

    public function executeQuery(string $sql, array $params = [], array $types = [], ?QueryCacheProfile $qcp = null): Result
    {
        self::failWhenDown();

        return parent::executeQuery($sql, $params, $types, $qcp);
    }

    public function executeStatement(string $sql, array $params = [], array $types = []): int|string
    {
        self::failWhenDown();

        return parent::executeStatement($sql, $params, $types);
    }

    private static function failWhenDown(): void
    {
        if (self::$databaseDown) {
            throw new RuntimeException('The database is down');
        }
    }
}

final class CommitFailingConnection extends Connection
{
    public static bool $failsCommit = false;

    public function commit(): void
    {
        if (self::$failsCommit && $this->getTransactionNestingLevel() === 1) {
            $this->rollBack();

            throw new RuntimeException('The commit failed');
        }

        parent::commit();
    }
}
