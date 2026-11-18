<?php

declare(strict_types=1);

namespace Test\Ecotone\Tracing;

use Ecotone\Api\Attribute\Around;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\ChannelInterceptor;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\InstantRetryConfiguration;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Interceptor\MethodInvocation;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\ConfiguredMessagingSystem;
use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Api\Tracing\RecordedHint;
use Ecotone\Api\Tracing\RecordedProcess;
use Ecotone\Api\Tracing\RecordedStep;
use Ecotone\Api\Tracing\TracingLevel;
use Ecotone\Test\LicenceTesting;
use RuntimeException;
use Throwable;

/**
 * licence Apache-2.0
 * @internal
 */
final class ExecutionRecordingStateLeakTest extends TracingTestCase
{
    public function test_a_message_received_by_hand_does_not_turn_the_next_unrelated_call_into_its_receive(): void
    {
        $ecotone = $this->application($this->ordering());

        $ecotone->sendCommandWithRouting('order.place', 'order-1');
        $ecotone->getServiceFromContainer(ConfiguredMessagingSystem::class)->getMessageChannelByName('orders')->receive();
        $ecotone->sendCommandWithRouting('audit.record', 'audit-1');

        $processes = $ecotone->getExecutionRecording()->processes;

        self::assertSame(['send command order.place', 'send command audit.record, class@anonymous::record'], self::titlesOf($ecotone));
        self::assertSame('gateway CommandBus::sendWithRouting', $processes[1]->steps[0]->label);
    }

    public function test_a_message_a_handler_takes_from_a_pollable_channel_itself_does_not_turn_the_next_unrelated_call_into_its_receive(): void
    {
        $handlers = new class () {
            #[Asynchronous('orders')]
            #[CommandHandler('order.place', endpointId: 'placeOrder')]
            public function place(string $order): void
            {
            }

            #[CommandHandler('orders.drain')]
            public function drain(string $reason, ConfiguredMessagingSystem $messagingSystem): void
            {
                $messagingSystem->getMessageChannelByName('orders')->receive();
            }

            #[CommandHandler('audit.record')]
            public function record(string $audit): void
            {
            }
        };
        $ecotone = $this->application($handlers);

        $ecotone->sendCommandWithRouting('order.place', 'order-1');
        $ecotone->sendCommandWithRouting('orders.drain', 'cancelled');
        $ecotone->sendCommandWithRouting('audit.record', 'audit-1');
        $processes = $ecotone->getExecutionRecording()->processes;

        self::assertSame('send command audit.record, class@anonymous::record', $processes[2]->title);
        self::assertSame('gateway CommandBus::sendWithRouting', $processes[2]->steps[0]->label);
    }

    public function test_each_message_a_consumer_started_by_a_console_command_handles_is_its_own_sub_flow_recorded_when_it_ends(): void
    {
        $recorder = new InMemorySpanRecorder();
        $orders = new class ($recorder) {
            public array $spansRecordedBefore = [];

            public function __construct(private InMemorySpanRecorder $recorder)
            {
            }

            #[Asynchronous('orders')]
            #[CommandHandler('order.place', endpointId: 'placeOrder')]
            public function place(string $order): void
            {
                $this->spansRecordedBefore[] = count($this->recorder->recordedSpans());
            }

            #[CommandHandler('audit.record')]
            public function record(string $audit): void
            {
            }
        };
        $ecotone = $this->application($orders, $recorder);

        foreach (['order-1', 'order-2', 'order-3'] as $order) {
            $ecotone->sendCommandWithRouting('order.place', $order);
        }
        $ecotone->runConsoleCommand('ecotone:run', ['consumerName' => 'orders', 'finishWhenNoMessages' => true]);
        $ecotone->sendCommandWithRouting('audit.record', 'audit-1');
        $processes = $ecotone->getExecutionRecording()->processes;
        $receives = array_values(array_filter($processes, fn (RecordedProcess $process): bool => str_starts_with($process->title, 'receive orders')));

        self::assertCount(3, $receives);
        self::assertSame([1, 2, 3], array_map(fn (RecordedProcess $receive): int => $receive->sentBy->process, $receives));
        self::assertLessThan($orders->spansRecordedBefore[1], $orders->spansRecordedBefore[0]);
        self::assertLessThan($orders->spansRecordedBefore[2], $orders->spansRecordedBefore[1]);
        self::assertStringStartsWith('send command audit.record', $processes[count($processes) - 1]->title);
        self::assertNull($processes[count($processes) - 1]->sentBy);
    }

    public function test_the_message_consumed_after_a_failing_asynchronous_handler_is_recorded_as_it_is_alone(): void
    {
        $orders = new class () {
            private bool $failed = false;

            #[Asynchronous('orders')]
            #[CommandHandler('order.place', endpointId: 'placeOrder')]
            public function place(string $order): void
            {
                if ($order === 'failing' && ! $this->failed) {
                    $this->failed = true;
                    throw new RuntimeException('the order cannot be placed');
                }
            }
        };
        $afterTheFailure = $this->application($orders);
        $afterTheFailure->sendCommandWithRouting('order.place', 'failing');
        $afterTheFailure->sendCommandWithRouting('order.place', 'order-2');
        $afterTheFailure->run('orders', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 2, stopOnError: false));
        $alone = $this->application($orders);
        $alone->sendCommandWithRouting('order.place', 'order-2');
        $alone->run('orders', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(self::stepsOf($alone->getExecutionRecording()->processes[1]), self::stepsOf($afterTheFailure->getExecutionRecording()->processes[3]));
        self::assertSame(2, $afterTheFailure->getExecutionRecording()->processes[3]->sentBy?->process);
    }

    public function test_the_call_after_a_failing_around_interceptor_is_recorded_as_it_is_alone(): void
    {
        $handlers = new class () {
            #[CommandHandler('order.place')]
            public function place(string $order): void
            {
            }

            #[CommandHandler('audit.record')]
            public function record(string $audit): void
            {
            }

            #[Around(pointcut: CommandHandler::class)]
            public function guard(MethodInvocation $invocation, Message $message): mixed
            {
                if ($message->getPayload() === 'failing') {
                    throw new RuntimeException('the order is refused');
                }

                return $invocation->proceed();
            }
        };

        $this->assertTheNextCallIsRecordedAsItIsAlone($handlers, fn (FlowTestSupport $ecotone) => $ecotone->sendCommandWithRouting('order.place', 'failing'));
    }

    public function test_the_call_after_a_failing_argument_conversion_is_recorded_as_it_is_alone(): void
    {
        $handlers = new class () {
            #[CommandHandler('order.place')]
            public function place(ExecutionRecordingLeakPlaceOrder $order): void
            {
            }

            #[CommandHandler('audit.record')]
            public function record(string $audit): void
            {
            }
        };

        $this->assertTheNextCallIsRecordedAsItIsAlone($handlers, fn (FlowTestSupport $ecotone) => $ecotone->sendCommandWithRouting('order.place', 'not an order'));
    }

    public function test_the_call_after_a_collected_message_whose_release_fails_is_recorded_as_it_is_alone(): void
    {
        $handlers = new class () {
            #[CommandHandler('order.place')]
            public function place(string $order, EventBus $eventBus): void
            {
                $eventBus->publishWithRouting('order.placed', $order);
            }

            #[Asynchronous('notifications')]
            #[EventHandler('order.placed', endpointId: 'notify')]
            public function notify(string $order): void
            {
            }

            #[ChannelInterceptor('notifications')]
            public function refuse(): void
            {
                throw new RuntimeException('the notifications channel refuses the message');
            }

            #[CommandHandler('audit.record')]
            public function record(string $audit): void
            {
            }
        };

        $this->assertTheNextCallIsRecordedAsItIsAlone(
            $handlers,
            fn (FlowTestSupport $ecotone) => $ecotone->sendCommandWithRouting('order.place', 'order-1'),
            [SimpleMessageChannelBuilder::createQueueChannel('notifications')],
        );
    }

    public function test_the_call_after_an_error_channel_send_that_fails_is_recorded_as_it_is_alone(): void
    {
        $handlers = new class () {
            #[Asynchronous('orders')]
            #[CommandHandler('order.place', endpointId: 'placeOrder')]
            public function place(string $order): void
            {
                throw new RuntimeException('the order cannot be placed');
            }

            #[InternalHandler('errors')]
            public function handleError(Message $error): void
            {
                throw new RuntimeException('the error cannot be stored');
            }

            #[CommandHandler('audit.record')]
            public function record(string $audit): void
            {
            }
        };

        $this->assertTheNextCallIsRecordedAsItIsAlone(
            $handlers,
            function (FlowTestSupport $ecotone): void {
                $ecotone->sendCommandWithRouting('order.place', 'order-1');
                $ecotone->run('orders', ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false));
            },
            [ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel('errors'), InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false)],
        );
    }

    public function test_the_call_after_an_instant_retry_that_finally_fails_is_recorded_as_it_is_alone(): void
    {
        $handlers = new class () {
            #[CommandHandler('order.place')]
            public function place(string $order): void
            {
                throw new RuntimeException('the order cannot be placed');
            }

            #[CommandHandler('audit.record')]
            public function record(string $audit): void
            {
            }
        };

        $this->assertTheNextCallIsRecordedAsItIsAlone(
            $handlers,
            fn (FlowTestSupport $ecotone) => $ecotone->sendCommandWithRouting('order.place', 'order-1'),
            [InstantRetryConfiguration::createWithDefaults()->withCommandBusRetry(true, 2, [RuntimeException::class])],
        );
    }

    public function test_the_call_after_a_channel_send_that_fails_inside_an_inline_delivery_is_recorded_as_it_is_alone(): void
    {
        $handlers = new class () {
            #[CommandHandler('order.place')]
            public function place(string $order, EventBus $eventBus): void
            {
                $eventBus->publishWithRouting('order.placed', $order);
            }

            #[EventHandler('order.placed')]
            public function whenPlaced(string $order, CommandBus $commandBus): void
            {
                $commandBus->sendWithRouting('order.ship', new ExecutionRecordingLeakOrderPlaced());
            }

            #[Asynchronous('shipping')]
            #[CommandHandler('order.ship', endpointId: 'ship')]
            public function ship(ExecutionRecordingLeakOrderPlaced $order): void
            {
            }

            #[CommandHandler('audit.record')]
            public function record(string $audit): void
            {
            }
        };

        $this->assertTheNextCallIsRecordedAsItIsAlone(
            $handlers,
            fn (FlowTestSupport $ecotone) => $ecotone->sendCommandWithRouting('order.place', 'order-1'),
            [SimpleMessageChannelBuilder::createQueueChannel('shipping', conversionMediaType: MediaType::createApplicationJson())],
        );
    }

    /**
     * @param list<object> $extensionObjects
     */
    private function assertTheNextCallIsRecordedAsItIsAlone(object $handlers, callable $failing, array $extensionObjects = []): void
    {
        $afterTheFailure = $this->application($handlers, extensionObjects: $extensionObjects);
        try {
            $failing($afterTheFailure);
            $failure = null;
        } catch (Throwable $thrown) {
            $failure = $thrown;
        }
        $afterTheFailure->sendCommandWithRouting('audit.record', 'audit-1');
        $alone = $this->application($handlers, extensionObjects: $extensionObjects);
        $alone->sendCommandWithRouting('audit.record', 'audit-1');
        $processes = $afterTheFailure->getExecutionRecording()->processes;
        $next = $processes[count($processes) - 1];

        self::assertNotNull($failure);
        self::assertNotEmpty(array_filter(array_slice($processes, 0, -1), fn (RecordedProcess $process): bool => self::failedIn($process)));
        self::assertSame('send command audit.record, class@anonymous::record', $next->title);
        self::assertNull($next->sentBy);
        self::assertSame(self::stepsOf($alone->getExecutionRecording()->processes[0]), self::stepsOf($next));
    }

    private static function failedIn(RecordedProcess $process): bool
    {
        foreach ($process->steps as $step) {
            foreach ($step->hints as $hint) {
                if ($hint->key === 'failure' && str_starts_with($hint->value, 'throws')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function stepsOf(RecordedProcess $process): array
    {
        return array_map(
            fn (RecordedStep $step): string => (string) preg_replace(['/\{generated#\d+\}/', '/process \d+/'], ['{generated}', 'process N'], str_repeat('  ', $step->depth) . $step->label . ' ' . implode('; ', array_map(fn (RecordedHint $hint): string => $hint->key . ': ' . $hint->value, $step->hints))),
            $process->steps,
        );
    }

    private function ordering(): object
    {
        return new class () {
            #[Asynchronous('orders')]
            #[CommandHandler('order.place', endpointId: 'placeOrder')]
            public function place(string $order): void
            {
            }

            #[CommandHandler('audit.record')]
            public function record(string $audit): void
            {
            }
        };
    }

    /**
     * @param list<object> $extensionObjects
     */
    private function application(object $handlers, ?InMemorySpanRecorder $recorder = null, array $extensionObjects = []): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            [$handlers::class],
            [$handlers, ...self::recordingSpansInto($recorder ?? new InMemorySpanRecorder())],
            [SimpleMessageChannelBuilder::createQueueChannel('orders'), ...$extensionObjects, self::tracingConfiguration()->withLevel(TracingLevel::Framework)],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    /**
     * @return list<string>
     */
    private static function titlesOf(FlowTestSupport $ecotone): array
    {
        return array_map(fn (RecordedProcess $process): string => $process->title, $ecotone->getExecutionRecording()->processes);
    }
}

final class ExecutionRecordingLeakPlaceOrder
{
}

final class ExecutionRecordingLeakOrderPlaced
{
}
