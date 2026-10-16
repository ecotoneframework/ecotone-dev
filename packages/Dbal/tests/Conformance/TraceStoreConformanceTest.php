<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Conformance;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Scheduling\Duration;
use Ecotone\Api\Scheduling\TimeSpan;
use Ecotone\Api\Tracing\OnTraceCompleted;
use Ecotone\Api\Tracing\SpanContext;
use Ecotone\Api\Tracing\TraceCompleted;
use Ecotone\Api\Tracing\TraceCompletion;
use Ecotone\Api\Tracing\TraceCompletionTimedOut;
use Ecotone\Api\Tracing\TracingConfiguration;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
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
        $this->expectExceptionMessage('Still pending: user.notifier.');

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
            extensionObjects: [TracingConfiguration::createWithDefaults()->withStoredTraces(inMemory: $implementation === 'in-memory', retention: $retention), ...$extensionObjects],
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
