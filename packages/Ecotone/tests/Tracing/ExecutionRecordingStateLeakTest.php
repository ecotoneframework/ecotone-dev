<?php

declare(strict_types=1);

namespace Test\Ecotone\Tracing;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\ConfiguredMessagingSystem;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Api\Tracing\RecordedProcess;
use Ecotone\Test\LicenceTesting;

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
            [SimpleMessageChannelBuilder::createQueueChannel('orders'), ...$extensionObjects, self::tracingConfiguration()->withExecutionRecording()],
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
