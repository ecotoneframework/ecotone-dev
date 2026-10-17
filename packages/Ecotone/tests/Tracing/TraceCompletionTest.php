<?php

declare(strict_types=1);

namespace Test\Ecotone\Tracing;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Headers;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\MessageGateway;
use Ecotone\Api\Attribute\Polling;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\EventSourcing\Event;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Projecting\FromStream;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Scheduling\Duration;
use Ecotone\Api\Tracing\CurrentTrace;
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
        $this->expectExceptionMessage("Trace {$trace->traceId} did not complete within 100 milliseconds. Still pending: user.notifier, async_channel. Run the consumer of the pending channel, or await with a longer timeout.");

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

        $this->assertContains('send command', $subscriber->spanNames);
        $this->assertContains('process class@anonymous::register', $subscriber->spanNames);
    }

    public function test_an_asynchronous_command_keeps_the_trace_pending_until_its_consumer_ran(): void
    {
        $orders = new class () {
            #[Asynchronous('async_channel')]
            #[CommandHandler('order.place', endpointId: 'order.placing')]
            public function place(string $orderId): void
            {
            }
        };
        $ecotone = $this->bootstrap([$orders], [SimpleMessageChannelBuilder::createQueueChannel('async_channel')]);
        $trace = SpanContext::newTrace();

        $ecotone->sendCommandWithRouting('order.place', '1', metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);

        $this->assertFalse($this->completion($ecotone)->isCompleted($trace->traceId));
        $this->assertFalse($this->completion($ecotone)->isCompletedFor($trace->traceId, 'order.placing'));

        $ecotone->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());

        $this->assertTrue($this->completion($ecotone)->isCompletedFor($trace->traceId, 'order.placing'));
        $this->assertTrue($this->completion($ecotone)->isCompleted($trace->traceId));
    }

    public function test_awaiting_an_asynchronous_command_names_its_channel_as_pending(): void
    {
        $orders = new class () {
            #[Asynchronous('async_channel')]
            #[CommandHandler('order.place', endpointId: 'order.placing')]
            public function place(string $orderId): void
            {
            }
        };
        $ecotone = $this->bootstrap([$orders], [SimpleMessageChannelBuilder::createQueueChannel('async_channel')]);
        $trace = SpanContext::newTrace();
        $ecotone->sendCommandWithRouting('order.place', '1', metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);

        $this->expectException(TraceCompletionTimedOut::class);
        $this->expectExceptionMessage('Still pending: order.placing, async_channel.');

        $this->completion($ecotone)->await($trace->traceId, Duration::milliseconds(100));
    }

    public function test_a_handler_that_succeeded_inside_a_failed_unit_of_work_is_not_completed(): void
    {
        $users = new class () {
            #[CommandHandler('user.register', endpointId: 'user.registration')]
            public function register(string $userId, CommandBus $commandBus): void
            {
                $commandBus->sendWithRouting('audit.record', $userId);

                throw new RuntimeException('Registration failed after the audit was recorded');
            }

            #[CommandHandler('audit.record', endpointId: 'audit.recording')]
            public function record(string $userId): void
            {
            }
        };
        $ecotone = $this->bootstrap([$users]);
        $trace = SpanContext::newTrace();

        try {
            $ecotone->sendCommandWithRouting('user.register', '1', metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);
            $this->fail('Expected the registration failure to propagate');
        } catch (RuntimeException) {
        }

        $this->assertFalse($this->completion($ecotone)->isCompletedFor($trace->traceId, 'audit.recording'));
        $this->assertFalse($this->completion($ecotone)->isCompletedFor($trace->traceId, 'user.registration'));
    }

    public function test_a_trace_completed_subscriber_sending_a_command_does_not_notify_itself_again(): void
    {
        $subscriber = new class () {
            public int $notifications = 0;

            #[OnTraceCompleted]
            public function whenCompleted(TraceCompleted $event, CommandBus $commandBus): void
            {
                $this->notifications++;
                if ($this->notifications < 5) {
                    $commandBus->sendWithRouting('cache.refresh', $event->traceId);
                }
            }

            #[CommandHandler('cache.refresh', endpointId: 'cache.refreshing')]
            public function refresh(string $traceId): void
            {
            }
        };
        $ecotone = $this->bootstrap([$this->users(), $subscriber]);

        $ecotone->sendCommandWithRouting('user.register', '1');

        $this->assertSame(1, $subscriber->notifications);
    }

    public function test_a_span_ended_subscriber_sending_a_command_does_not_notify_itself_again(): void
    {
        $subscriber = new class () {
            /** @var string[] */
            public array $notifiedTraces = [];

            #[OnSpanEnded]
            public function whenEnded(SpanEnded $event, CommandBus $commandBus): void
            {
                $this->notifiedTraces[] = $event->span->traceId;
                if (count($this->notifiedTraces) < 20) {
                    $commandBus->sendWithRouting('cache.refresh', $event->span->traceId);
                }
            }

            #[CommandHandler('cache.refresh', endpointId: 'cache.refreshing')]
            public function refresh(string $traceId): void
            {
            }
        };
        $ecotone = $this->bootstrap([$this->users(), $subscriber]);
        $trace = SpanContext::newTrace();

        $ecotone->sendCommandWithRouting('user.register', '1', metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);

        $this->assertSame([$trace->traceId], array_values(array_unique($subscriber->notifiedTraces)));
    }

    public function test_a_consumer_that_swallowed_its_handler_failure_leaves_the_trace_pending(): void
    {
        $orders = new class () {
            #[Asynchronous('async_channel')]
            #[CommandHandler('order.place', endpointId: 'order.placing')]
            public function place(string $orderId): void
            {
                throw new RuntimeException('Placing failed');
            }
        };
        $ecotone = $this->bootstrap([$orders], [SimpleMessageChannelBuilder::createQueueChannel('async_channel'), ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel('errorChannel')]);
        $trace = SpanContext::newTrace();
        $ecotone->sendCommandWithRouting('order.place', '1', metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);

        $ecotone->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false));

        $this->assertFalse($this->completion($ecotone)->isCompleted($trace->traceId));
        $this->assertFalse($this->completion($ecotone)->isCompletedFor($trace->traceId, 'order.placing'));
    }

    public function test_a_consumer_that_swallowed_its_handler_failure_stores_a_failed_consumer_span_when_failures_may_be_stored(): void
    {
        $orders = new class () {
            #[Asynchronous('async_channel')]
            #[CommandHandler('order.place', endpointId: 'order.placing')]
            public function place(string $orderId): void
            {
                throw new RuntimeException('Placing failed');
            }
        };
        $subscriber = $this->spanEndedSubscriber();
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$orders::class, $subscriber::class],
            [$orders, $subscriber],
            [TracingConfiguration::createWithDefaults()->withStoredTraces(storeFailuresOutsideTransaction: true), SimpleMessageChannelBuilder::createQueueChannel('async_channel'), ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel('errorChannel')],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $trace = SpanContext::newTrace();
        $ecotone->sendCommandWithRouting('order.place', '1', metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);

        $ecotone->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false));

        $this->assertSame(SpanStatus::Error, $subscriber->endedSpans['receive async_channel']);
        $this->assertFalse($this->completion($ecotone)->isCompleted($trace->traceId));
    }

    public function test_a_consumer_whose_handler_failed_once_and_succeeded_on_the_instant_retry_completes_the_trace(): void
    {
        $orders = new class () {
            public int $attempts = 0;

            #[Asynchronous('async_channel')]
            #[CommandHandler('order.place', endpointId: 'order.placing')]
            public function place(string $orderId): void
            {
                if (++$this->attempts === 1) {
                    throw new RuntimeException('Placing failed once');
                }
            }
        };
        $ecotone = $this->bootstrap([$orders], [SimpleMessageChannelBuilder::createQueueChannel('async_channel'), ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel('errorChannel')]);
        $trace = SpanContext::newTrace();
        $ecotone->sendCommandWithRouting('order.place', '1', metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);

        $ecotone->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false));

        $this->assertSame(2, $orders->attempts);
        $this->assertTrue($this->completion($ecotone)->isCompleted($trace->traceId));
    }

    public function test_an_output_channel_after_an_asynchronous_handler_does_not_complete_that_handler_for_a_delivery_still_queued(): void
    {
        $notifier = new class () {
            #[Asynchronous('async_channel')]
            #[EventHandler('user.registered', outputChannelName: 'audit.record', endpointId: 'user.notifier')]
            public function notify(string $userId): string
            {
                return $userId;
            }

            #[InternalHandler('audit.record', endpointId: 'audit.recording')]
            public function record(string $userId): void
            {
            }
        };
        $ecotone = $this->bootstrap([$notifier], [SimpleMessageChannelBuilder::createQueueChannel('async_channel')]);
        $trace = SpanContext::newTrace();
        $ecotone->publishEventWithRouting('user.registered', '1', metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);
        $ecotone->publishEventWithRouting('user.registered', '2', metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);

        $ecotone->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1));

        $this->assertTrue($this->completion($ecotone)->isCompletedFor($trace->traceId, 'audit.recording'));
        $this->assertFalse($this->completion($ecotone)->isCompletedFor($trace->traceId, 'user.notifier'));
        $this->assertFalse($this->completion($ecotone)->isCompleted($trace->traceId));

        $ecotone->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1));

        $this->assertTrue($this->completion($ecotone)->isCompletedFor($trace->traceId, 'user.notifier'));
        $this->assertTrue($this->completion($ecotone)->isCompleted($trace->traceId));
    }

    public function test_a_flow_started_through_a_message_gateway_is_pending_until_its_asynchronous_handler_ran(): void
    {
        $notifications = new class () {
            #[InternalHandler('notification.send', outputChannelName: 'notification.deliver', endpointId: 'notification.sending')]
            public function send(string $text): string
            {
                return $text;
            }

            #[Asynchronous('async_channel')]
            #[InternalHandler('notification.deliver', endpointId: 'notification.delivering')]
            public function deliver(string $text): void
            {
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$notifications::class, TracedNotificationGateway::class],
            [$notifications],
            [TracingConfiguration::createWithDefaults()->withStoredTraces(), SimpleMessageChannelBuilder::createQueueChannel('async_channel')],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $trace = SpanContext::newTrace();

        $ecotone->getGateway(TracedNotificationGateway::class)->send('hello', [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);

        $this->assertTrue($this->completion($ecotone)->isCompletedFor($trace->traceId, 'notification.sending'));
        $this->assertFalse($this->completion($ecotone)->isCompleted($trace->traceId));

        $ecotone->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());

        $this->assertTrue($this->completion($ecotone)->isCompleted($trace->traceId));
    }

    public function test_the_completion_gateway_without_stored_traces_names_the_configuration_it_needs(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting([$this->users()::class], [$this->users()]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('TraceCompletion needs stored traces, which decide when a trace is complete. Register TracingConfiguration::createWithDefaults()->withStoredTraces() from a #[ServiceContext] method.');

        $this->completion($ecotone)->isCompleted('0af7651916cd43dd8448eb211c80319c');
    }

    public function test_awaiting_a_trace_whose_handler_failed_names_the_failed_handler(): void
    {
        $notifier = new class () {
            #[Asynchronous('async_channel')]
            #[EventHandler('user.registered', endpointId: 'user.notifier')]
            public function notify(string $userId): void
            {
                throw new RuntimeException('Notification failed');
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$this->users()::class, $notifier::class],
            [$this->users(), $notifier],
            [TracingConfiguration::createWithDefaults()->withStoredTraces(storeFailuresOutsideTransaction: true), SimpleMessageChannelBuilder::createQueueChannel('async_channel'), ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel('errorChannel')],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $trace = SpanContext::newTrace();
        $ecotone->sendCommandWithRouting('user.register', '1', metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);
        $ecotone->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false));

        $this->expectException(TraceCompletionTimedOut::class);
        $this->expectExceptionMessage("Trace {$trace->traceId} did not complete within 100 milliseconds. Failed: user.notifier, whose last attempt failed and whose message is in the error channel or awaiting a retry. Await with a longer timeout once the failure is resolved.");

        $this->completion($ecotone)->await($trace->traceId, Duration::milliseconds(100));
    }

    public function test_awaiting_a_trace_nobody_recorded_says_so(): void
    {
        $ecotone = $this->bootstrap([$this->users()]);

        $this->expectException(TraceCompletionTimedOut::class);
        $this->expectExceptionMessage('No span was recorded under trace 0af7651916cd43dd8448eb211c80319c within 100 milliseconds. Send the command with that traceparent in its metadata, and check that the process handling it stores traces.');

        $this->completion($ecotone)->await('0af7651916cd43dd8448eb211c80319c', Duration::milliseconds(100));
    }

    public function test_awaiting_a_destination_the_trace_never_reached_names_it_as_unknown(): void
    {
        $ecotone = $this->bootstrap([$this->users()]);
        $trace = SpanContext::newTrace();
        $ecotone->sendCommandWithRouting('user.register', '1', metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);

        $this->expectException(TraceCompletionTimedOut::class);
        $this->expectExceptionMessage("Trace {$trace->traceId} was neither sent to nor processed by user.notifierr within 100 milliseconds. Check the endpointId or projection name; the trace reached: user.registration.");

        $this->completion($ecotone)->awaitFor($trace->traceId, 'user.notifierr', Duration::milliseconds(100));
    }

    public function test_two_asynchronous_commands_to_one_handler_complete_it_only_after_both_ran(): void
    {
        $orders = new class () {
            #[Asynchronous('async_channel')]
            #[CommandHandler('order.place', endpointId: 'order.placing')]
            public function place(string $orderId): void
            {
            }
        };
        $ecotone = $this->bootstrap([$orders], [SimpleMessageChannelBuilder::createQueueChannel('async_channel')]);
        $trace = SpanContext::newTrace();
        $ecotone->sendCommandWithRouting('order.place', '1', metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);
        $ecotone->sendCommandWithRouting('order.place', '2', metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);

        $ecotone->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1));

        $this->assertFalse($this->completion($ecotone)->isCompletedFor($trace->traceId, 'order.placing'));

        $ecotone->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1));

        $this->assertTrue($this->completion($ecotone)->isCompletedFor($trace->traceId, 'order.placing'));
    }

    public function test_awaiting_a_polling_projection_is_refused_because_it_runs_outside_the_trace(): void
    {
        $projection = new #[Projection('orders_list'), FromStream('orders_stream'), Polling('orders_list_polling')] class () {
            #[EventHandler('order.placed')]
            public function whenPlaced(array $event): void
            {
            }
        };
        $ecotone = $this->bootstrap([$projection]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("Projection orders_list runs by #[Polling], so it is not part of the trace that appended its events and TraceCompletion cannot await it. Await the handler that published the event, or make the projection event-driven by removing #[Polling].");

        $this->completion($ecotone)->awaitFor('0af7651916cd43dd8448eb211c80319c', 'orders_list', Duration::milliseconds(10));
    }

    public function test_a_handler_can_read_the_trace_id_it_runs_in(): void
    {
        $users = new class () {
            public ?string $traceId = null;

            #[CommandHandler('user.register', endpointId: 'user.registration')]
            public function register(string $userId, #[Reference] CurrentTrace $currentTrace): void
            {
                $this->traceId = $currentTrace->traceId();
            }
        };
        $ecotone = $this->bootstrap([$users]);
        $trace = SpanContext::newTrace();

        $ecotone->sendCommandWithRouting('user.register', '1', metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);

        $this->assertSame($trace->traceId, $users->traceId);
        $this->assertTrue($this->completion($ecotone)->isCompleted($users->traceId));
    }

    public function test_reading_the_current_trace_without_tracing_names_how_to_enable_it(): void
    {
        $users = new class () {
            #[CommandHandler('user.register', endpointId: 'user.registration')]
            public function register(string $userId, #[Reference] CurrentTrace $currentTrace): void
            {
                $currentTrace->traceId();
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([$users::class], [$users]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('No trace is recorded, so there is no current trace id. Enable tracing: register TracingConfiguration::createWithDefaults()->withSpanRecorder() or ->withStoredTraces() from a #[ServiceContext] method, or install ecotone/open-telemetry with a TracerProviderInterface.');

        $ecotone->sendCommandWithRouting('user.register', '1');
    }

    public function test_a_failed_unit_of_work_stores_nothing_by_default(): void
    {
        $subscriber = $this->spanEndedSubscriber();
        $ecotone = $this->bootstrap([$this->failingUsers(), $subscriber]);
        $trace = SpanContext::newTrace();

        try {
            $ecotone->sendCommandWithRouting('user.register', '1', metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);
            $this->fail('Expected the registration failure to propagate');
        } catch (RuntimeException) {
        }

        $this->assertSame([], $subscriber->endedSpans);
        $this->assertFalse($this->completion($ecotone)->isCompleted($trace->traceId));
    }

    public function test_a_failed_unit_of_work_stores_its_failed_root_span_when_failures_may_be_stored_outside_the_transaction(): void
    {
        $subscriber = $this->spanEndedSubscriber();
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$this->failingUsers()::class, $subscriber::class],
            [$this->failingUsers(), $subscriber],
            [TracingConfiguration::createWithDefaults()->withStoredTraces(storeFailuresOutsideTransaction: true)],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $trace = SpanContext::newTrace();

        try {
            $ecotone->sendCommandWithRouting('user.register', '1', metadata: [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);
            $this->fail('Expected the registration failure to propagate');
        } catch (RuntimeException) {
        }

        $this->assertSame(['process class@anonymous::register' => SpanStatus::Error, 'send command' => SpanStatus::Error], array_combine(array_map(fn (string $name): string => preg_replace('/^process class@anonymous\\S*::/', 'process class@anonymous::', $name), array_keys($subscriber->endedSpans)), $subscriber->endedSpans));
        $this->assertTrue($this->completion($ecotone)->isCompleted($trace->traceId));
    }

    public function test_stored_traces_without_a_trace_store_are_refused_at_bootstrap(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('TracingConfiguration::withStoredTraces() needs a trace store and none is registered. Enable the DBAL package with a connection to store traces in the database, or call withStoredTraces(inMemory: true) to keep them in this process only.');

        EcotoneLite::bootstrapFlowTesting(
            [],
            [],
            [TracingConfiguration::createWithDefaults()->withStoredTraces()],
            enabledModules: [ModulePackageList::CORE_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    public function test_stored_traces_require_an_enterprise_licence(): void
    {
        $this->expectException(LicensingException::class);
        $this->expectExceptionMessage('TracingConfiguration::withStoredTraces() requires an Ecotone Enterprise licence. Remove it to run without one.');

        EcotoneLite::bootstrapFlowTesting(
            [],
            [],
            [TracingConfiguration::createWithDefaults()->withStoredTraces()],
        );
    }

    public function test_a_trace_completed_subscriber_requires_an_enterprise_licence(): void
    {
        $subscriber = new class () {
            #[OnTraceCompleted]
            public function whenCompleted(TraceCompleted $event): void
            {
            }
        };

        $this->expectException(LicensingException::class);
        $this->expectExceptionMessage('TracingConfiguration::withStoredTraces() and #[OnTraceCompleted] on ' . $subscriber::class . '::whenCompleted require an Ecotone Enterprise licence. Remove them to run without one.');

        EcotoneLite::bootstrapFlowTesting(
            [$subscriber::class],
            [$subscriber],
            [TracingConfiguration::createWithDefaults()->withStoredTraces()],
        );
    }

    public function test_a_span_ended_subscriber_requires_an_enterprise_licence_even_without_stored_traces(): void
    {
        $subscriber = new class () {
            #[OnSpanEnded]
            public function whenEnded(SpanEnded $event): void
            {
            }
        };

        $this->expectException(LicensingException::class);
        $this->expectExceptionMessage('#[OnSpanEnded] on ' . $subscriber::class . '::whenEnded requires an Ecotone Enterprise licence. Remove it to run without one.');

        EcotoneLite::bootstrapFlowTesting(
            [$subscriber::class],
            [$subscriber],
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

    private function failingUsers(): object
    {
        return new class () {
            #[CommandHandler('user.register', endpointId: 'user.registration')]
            public function register(string $userId): void
            {
                throw new RuntimeException('Registration failed');
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

interface TracedNotificationGateway
{
    #[MessageGateway('notification.send')]
    public function send(string $text, #[Headers] array $metadata): void;
}
