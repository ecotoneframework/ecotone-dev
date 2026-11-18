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
