<?php

declare(strict_types=1);

namespace Test\Ecotone\Tracing;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\EventSourcing\Event;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Projecting\FromStream;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Scheduling\Duration;
use Ecotone\Api\Tracing\OnSpanEnded;
use Ecotone\Api\Tracing\OnTraceCompleted;
use Ecotone\Api\Tracing\SpanContext;
use Ecotone\Api\Tracing\SpanEnded;
use Ecotone\Api\Tracing\TraceCompleted;
use Ecotone\Api\Tracing\TraceCompletion;
use Ecotone\Api\Tracing\TraceCompletionTimedOut;
use Ecotone\Api\Tracing\TracingConfiguration;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Support\LicensingException;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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
final class TraceCompletionTest extends TestCase
{
    public function test_a_command_handled_synchronously_is_completed_when_the_bus_returns(): void
    {
        $ecotone = $this->bootstrap([$this->users()]);
        $trace = SpanContext::newTrace();

        $ecotone->sendCommandWithRouting('user.register', '1', metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);

        $this->assertTrue($this->completion($ecotone)->isCompleted($trace->traceId));
        $this->assertTrue($this->completion($ecotone)->isCompletedFor($trace->traceId, 'user.registration'));
    }

    public function test_an_asynchronous_handler_keeps_the_trace_pending_until_its_consumer_ran(): void
    {
        $ecotone = $this->bootstrap([$this->users(), $this->asynchronousNotifier()], [SimpleMessageChannelBuilder::createQueueChannel('async_channel')]);
        $trace = SpanContext::newTrace();

        $ecotone->sendCommandWithRouting('user.register', '1', metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);

        $this->assertFalse($this->completion($ecotone)->isCompleted($trace->traceId));
        $this->assertTrue($this->completion($ecotone)->isCompletedFor($trace->traceId, 'user.registration'));
        $this->assertFalse($this->completion($ecotone)->isCompletedFor($trace->traceId, 'user.notifier'));

        $ecotone->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());

        $this->assertTrue($this->completion($ecotone)->isCompletedFor($trace->traceId, 'user.notifier'));
        $this->assertTrue($this->completion($ecotone)->isCompleted($trace->traceId));
    }

    public function test_an_asynchronous_projection_keeps_the_trace_pending_until_it_projected(): void
    {
        $projection = new #[Projection('users_list'), FromStream('users_stream'), Asynchronous('async_channel')] class () {
            #[EventHandler('user.registered')]
            public function whenRegistered(array $event): void
            {
            }
        };
        $ecotone = $this->bootstrap([$projection], [SimpleMessageChannelBuilder::createQueueChannel('async_channel')]);
        $ecotone->withEvents([Event::createWithType('user.registered', ['userId' => '1'])]);
        $trace = SpanContext::newTrace();

        $ecotone->publishEventWithRouting('user.registered', [], metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);

        $this->assertFalse($this->completion($ecotone)->isCompletedFor($trace->traceId, 'users_list'));

        $ecotone->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());

        $this->assertTrue($this->completion($ecotone)->isCompletedFor($trace->traceId, 'users_list'));
        $this->assertTrue($this->completion($ecotone)->isCompleted($trace->traceId));
    }

    public function test_awaiting_a_pending_trace_times_out_naming_what_is_still_pending(): void
    {
        $ecotone = $this->bootstrap([$this->users(), $this->asynchronousNotifier()], [SimpleMessageChannelBuilder::createQueueChannel('async_channel')]);
        $trace = SpanContext::newTrace();
        $ecotone->sendCommandWithRouting('user.register', '1', metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);

        $this->expectException(TraceCompletionTimedOut::class);
        $this->expectExceptionMessage("Trace {$trace->traceId} did not complete within 100 milliseconds. Still pending: user.notifier. Run the consumer of the channel those handlers are attached to, or await with a longer timeout.");

        $this->completion($ecotone)->await($trace->traceId, Duration::milliseconds(100));
    }

    public function test_awaiting_a_completed_trace_returns_at_once(): void
    {
        $ecotone = $this->bootstrap([$this->users()]);
        $trace = SpanContext::newTrace();
        $ecotone->sendCommandWithRouting('user.register', '1', metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);

        $this->completion($ecotone)->await($trace->traceId, Duration::milliseconds(100));
        $this->completion($ecotone)->awaitFor($trace->traceId, 'user.registration', Duration::milliseconds(100));

        $this->assertTrue($this->completion($ecotone)->isCompleted($trace->traceId));
    }

    public function test_a_synchronous_handler_without_an_explicit_endpoint_id_still_completes_its_route(): void
    {
        $notifier = new class () {
            #[EventHandler('user.registered')]
            public function notify(string $userId): void
            {
            }
        };
        $ecotone = $this->bootstrap([$this->users(), $notifier]);
        $trace = SpanContext::newTrace();

        $ecotone->sendCommandWithRouting('user.register', '1', metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);

        $this->assertTrue($this->completion($ecotone)->isCompleted($trace->traceId));
    }

    public function test_a_trace_nobody_recorded_is_not_completed(): void
    {
        $ecotone = $this->bootstrap([$this->users()]);

        $this->assertFalse($this->completion($ecotone)->isCompleted(SpanContext::newTrace()->traceId));
    }

    public function test_a_failing_asynchronous_handler_leaves_the_trace_pending(): void
    {
        $notifier = new class () {
            #[Asynchronous('async_channel')]
            #[EventHandler('user.registered', endpointId: 'user.notifier')]
            public function notify(string $userId): void
            {
                throw new RuntimeException('Notification failed');
            }
        };
        $ecotone = $this->bootstrap([$this->users(), $notifier], [SimpleMessageChannelBuilder::createQueueChannel('async_channel')]);
        $trace = SpanContext::newTrace();
        $ecotone->sendCommandWithRouting('user.register', '1', metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);

        try {
            $ecotone->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());
        } catch (RuntimeException) {
        }

        $this->assertFalse($this->completion($ecotone)->isCompleted($trace->traceId));
        $this->assertFalse($this->completion($ecotone)->isCompletedFor($trace->traceId, 'user.notifier'));
    }

    public function test_a_trace_completed_subscriber_is_called_once_after_the_last_handler_committed(): void
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
        $ecotone = $this->bootstrap([$this->users(), $this->asynchronousNotifier(), $subscriber], [SimpleMessageChannelBuilder::createQueueChannel('async_channel')]);
        $trace = SpanContext::newTrace();

        $ecotone->sendCommandWithRouting('user.register', '1', metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);
        $this->assertSame([], $subscriber->completedTraces);

        $ecotone->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $this->assertSame([$trace->traceId], $subscriber->completedTraces);

        $ecotone->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $this->assertSame([$trace->traceId], $subscriber->completedTraces);
    }

    public function test_a_synchronous_trace_notifies_its_subscriber_when_the_bus_returns(): void
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
        $ecotone = $this->bootstrap([$this->users(), $subscriber]);
        $trace = SpanContext::newTrace();

        $ecotone->sendCommandWithRouting('user.register', '1', metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);

        $this->assertSame([$trace->traceId], $subscriber->completedTraces);
    }

    public function test_a_span_ended_subscriber_receives_every_stored_span_of_the_trace(): void
    {
        $subscriber = new class () {
            /** @var string[] */
            public array $spanNames = [];

            #[OnSpanEnded]
            public function whenEnded(SpanEnded $event): void
            {
                $this->spanNames[] = $event->span->name;
            }
        };
        $users = $this->users();
        $ecotone = $this->bootstrap([$users, $subscriber]);

        $ecotone->sendCommandWithRouting('user.register', '1');

        $this->assertContains('Command Bus', $subscriber->spanNames);
        $this->assertContains('Command Handler: ' . $users::class . '::register', $subscriber->spanNames);
    }

    public function test_stored_traces_require_an_enterprise_licence(): void
    {
        $this->expectException(LicensingException::class);
        $this->expectExceptionMessage('Stored traces and TraceCompletion require an Ecotone Enterprise licence.');

        EcotoneLite::bootstrapFlowTesting(
            [],
            [],
            [TracingConfiguration::createWithDefaults()->withStoredTraces()],
        );
    }

    public function test_a_trace_completed_subscriber_without_stored_traces_is_refused_at_bootstrap(): void
    {
        $subscriber = new class () {
            #[OnTraceCompleted]
            public function whenCompleted(TraceCompleted $event): void
            {
            }
        };

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('#[OnTraceCompleted] on ' . $subscriber::class . '::whenCompleted needs stored traces, which decide when a trace is complete. Register TracingConfiguration::createWithDefaults()->withStoredTraces() from a #[ServiceContext] method.');

        EcotoneLite::bootstrapFlowTesting(
            [$subscriber::class],
            [$subscriber],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    /**
     * @param object[] $services
     * @param object[] $extensionObjects
     */
    private function bootstrap(array $services, array $extensionObjects = []): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            array_map(fn (object $service): string => $service::class, $services),
            $services,
            [TracingConfiguration::createWithDefaults()->withStoredTraces(), ...$extensionObjects],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
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
