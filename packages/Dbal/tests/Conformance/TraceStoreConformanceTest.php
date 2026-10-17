<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Conformance;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalDeadLetterBuilder;
use Ecotone\Api\Dbal\ExtensionObject\MultiTenantConfiguration;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\InstantRetryConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Scheduling\Duration;
use Ecotone\Api\Scheduling\TimeSpan;
use Ecotone\Api\Tracing\OnSpanEnded;
use Ecotone\Api\Tracing\OnTraceCompleted;
use Ecotone\Api\Tracing\SpanContext;
use Ecotone\Api\Tracing\SpanEnded;
use Ecotone\Api\Tracing\SpanStatus;
use Ecotone\Api\Tracing\TraceCompleted;
use Ecotone\Api\Tracing\TraceCompletion;
use Ecotone\Api\Tracing\TraceCompletionTimedOut;
use Ecotone\Api\Tracing\TracingConfiguration;
use Ecotone\Messaging\Config\ConfigurationException;
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
final class TraceStoreConformanceTest extends DbalMessagingTestCase
{
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

        $this->expectException(TraceCompletionTimedOut::class);
        $this->expectExceptionMessage('Still pending: user.notifier, async_channel. Run the consumer of the pending channel');

        $this->completion($ecotone)->await($trace->traceId, Duration::milliseconds(100));
    }

    #[DataProvider('implementations')]
    public function test_a_trace_completed_subscriber_is_called_once_after_the_last_handler_committed(string $implementation): void
    {
        $subscriber = new class () {
            /** @var string[] */
            public array $completedTraces = [];

            #[OnTraceCompleted]
            public function whenCompleted(TraceCompleted $event): void
            {
                $this->completedTraces[] = $event->traceId;
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
        $subscriber = $this->spanEndedSubscriber();
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
    public function test_a_failing_asynchronous_handler_stores_its_failed_consumer_span_when_failures_may_be_stored_outside_the_transaction(string $implementation): void
    {
        $subscriber = $this->spanEndedSubscriber();
        $ecotone = $this->bootstrap($implementation, [$this->users(), $this->failingNotifier(), $subscriber], [SimpleMessageChannelBuilder::createQueueChannel('async_channel')], storeFailuresOutsideTransaction: true);
        $trace = SpanContext::newTrace();
        $ecotone->sendCommandWithRouting('user.register', '1', metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);

        try {
            $ecotone->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());
        } catch (RuntimeException) {
        }

        self::assertSame(SpanStatus::Error, $subscriber->endedSpans['receive async_channel']);
        self::assertFalse($this->completion($ecotone)->isCompleted($trace->traceId));
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
    public function test_a_subscriber_that_throws_does_not_fail_the_committed_command_and_is_notified_again_later(string $implementation): void
    {
        $subscriber = new class () {
            /** @var string[] */
            public array $completedTraces = [];

            #[OnTraceCompleted]
            public function whenCompleted(TraceCompleted $event): void
            {
                $this->completedTraces[] = $event->traceId;
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

        $ecotone->advanceTimeBy(TimeSpan::withSeconds(31));
        $ecotone->sendCommandWithRouting('user.register', '2', metadata: [MessageHeaders::TRACEPARENT => $second->toTraceparent()]);

        self::assertEqualsCanonicalizing([$first->traceId, $first->traceId, $second->traceId], $subscriber->completedTraces);
    }

    #[DataProvider('implementations')]
    public function test_a_trace_that_receives_new_work_after_completing_is_notified_again_when_it_completes_again(string $implementation): void
    {
        $subscriber = new class () {
            /** @var string[] */
            public array $completedTraces = [];

            #[OnTraceCompleted]
            public function whenCompleted(TraceCompleted $event): void
            {
                $this->completedTraces[] = $event->traceId;
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

    public function test_stored_traces_follow_the_default_connection_of_a_multi_tenant_application(): void
    {
        $ecotone = $this->bootstrapFlowTesting(
            classesToResolve: [$this->users()::class, $this->asynchronousNotifier()::class],
            containerOrAvailableServices: [$this->users(), $this->asynchronousNotifier(), 'tenant_a_connection' => $this->getConnectionFactory(), 'shared_connection' => $this->getConnectionFactory()],
            extensionObjects: [
                TracingConfiguration::createWithDefaults()->withStoredTraces(),
                MultiTenantConfiguration::createWithDefaultConnection('tenant', ['tenant_a' => 'tenant_a_connection'], 'shared_connection'),
                SimpleMessageChannelBuilder::createQueueChannel('async_channel'),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        )->setupDatabaseTables();
        $trace = SpanContext::newTrace();

        $ecotone->sendCommandWithRouting('user.register', '1', metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent(), 'tenant' => 'tenant_a']);
        self::assertFalse($this->completion($ecotone)->isCompleted($trace->traceId));

        $ecotone->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertTrue($this->completion($ecotone)->isCompleted($trace->traceId));
    }

    public function test_stored_traces_in_a_multi_tenant_application_without_a_default_connection_are_refused_at_bootstrap(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Stored traces are written after the unit of work ended, when no tenant is active, so they need a connection that does not depend on the tenant. Use MultiTenantConfiguration::createWithDefaultConnection() to name it, or withStoredTraces(inMemory: true) for a single process.');

        $this->bootstrapFlowTesting(
            classesToResolve: [$this->users()::class],
            containerOrAvailableServices: [$this->users(), 'tenant_a_connection' => $this->getConnectionFactory()],
            extensionObjects: [
                TracingConfiguration::createWithDefaults()->withStoredTraces(),
                MultiTenantConfiguration::create('tenant', ['tenant_a' => 'tenant_a_connection']),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
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
    private function bootstrap(string $implementation, array $services, array $extensionObjects = [], ?Duration $retention = null, bool $storeFailuresOutsideTransaction = false): FlowTestSupport
    {
        return $this->bootstrapFlowTesting(
            classesToResolve: array_map(fn (object $service): string => $service::class, $services),
            containerOrAvailableServices: [...$services, DbalConnectionFactory::class => $this->getConnectionFactory()],
            extensionObjects: [TracingConfiguration::createWithDefaults()->withStoredTraces(inMemory: $implementation === 'in-memory', retention: $retention, storeFailuresOutsideTransaction: $storeFailuresOutsideTransaction), ...$extensionObjects],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        )->setupDatabaseTables();
    }

    private function completion(FlowTestSupport $ecotone): TraceCompletion
    {
        return $ecotone->getGateway(TraceCompletion::class);
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

    private function spanEndedSubscriber(): object
    {
        return new class () {
            /** @var array<string, SpanStatus> */
            public array $endedSpans = [];

            #[OnSpanEnded]
            public function whenEnded(SpanEnded $event): void
            {
                $this->endedSpans[$event->span->name] = $event->span->status;
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
