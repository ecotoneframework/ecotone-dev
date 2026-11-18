<?php

declare(strict_types=1);

namespace Test\Ecotone\Tracing;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Distributed;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Test\Flow\Snapshot\FlowExecution;
use Ecotone\Test\LicenceTesting;
use RuntimeException;

/**
 * licence Apache-2.0
 * @internal
 */
final class ExecutionRecordingSubFlowTest extends TracingTestCase
{
    public function test_a_consumer_run_is_a_sub_flow_of_the_process_and_the_step_that_sent_its_message(): void
    {
        $processes = $this->recorded([$this->ordering()], function (FlowTestSupport $ecotone): void {
            $ecotone->sendCommandWithRouting('order.place', []);
            $ecotone->run('notifications', ExecutionPollingMetadata::createWithTestingSetup());
        });

        self::assertArrayNotHasKey('sentBy', $processes[0]);
        self::assertSame(1, $processes[1]['sentBy']['process']);
        self::assertSame('release collected message to notifications', $processes[0]['steps'][$processes[1]['sentBy']['step']]['label']);
    }

    public function test_an_event_published_to_two_consumers_makes_each_a_sub_flow_of_its_own_send(): void
    {
        $processes = $this->recorded([$this->ordering(), $this->auditing()], function (FlowTestSupport $ecotone): void {
            $ecotone->sendCommandWithRouting('order.place', []);
            $ecotone->run('notifications', ExecutionPollingMetadata::createWithTestingSetup());
        });

        self::assertSame([1, 1], [$processes[1]['sentBy']['process'], $processes[2]['sentBy']['process']]);
        self::assertNotSame($processes[1]['sentBy']['step'], $processes[2]['sentBy']['step']);
        self::assertSame(
            ['release collected message to notifications', 'release collected message to notifications'],
            [$processes[0]['steps'][$processes[1]['sentBy']['step']]['label'], $processes[0]['steps'][$processes[2]['sentBy']['step']]['label']],
        );
    }

    public function test_a_message_the_in_memory_queue_delivers_again_without_its_trace_context_is_a_flow_and_its_first_delivery_a_sub_flow(): void
    {
        $orders = new class () {
            public int $attempts = 0;

            #[Asynchronous('orders')]
            #[CommandHandler('order.place', endpointId: 'placeOrder')]
            public function place(array $order): void
            {
                if (++$this->attempts === 1) {
                    throw new RuntimeException('the first delivery fails');
                }
            }
        };

        $processes = $this->recorded([$orders], function (FlowTestSupport $ecotone): void {
            $ecotone->sendCommandWithRouting('order.place', []);
            $ecotone->run('orders', ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false));
            $ecotone->run('orders', ExecutionPollingMetadata::createWithTestingSetup());
        }, [SimpleMessageChannelBuilder::createQueueChannel('orders')]);

        self::assertCount(3, $processes);
        self::assertSame(1, $processes[1]['sentBy']['process']);
        self::assertArrayNotHasKey('sentBy', $processes[2]);
    }

    public function test_a_process_whose_message_was_sent_outside_the_recording_is_a_flow(): void
    {
        $processes = $this->recorded([$this->ordering()], function (FlowTestSupport $ecotone): void {
            $ecotone->sendCommandWithRouting('order.place', [], metadata: [MessageHeaders::TRACEPARENT => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01']);
            $ecotone->run('notifications', ExecutionPollingMetadata::createWithTestingSetup());
        });

        self::assertArrayNotHasKey('sentBy', $processes[0]);
        self::assertSame(1, $processes[1]['sentBy']['process']);
    }

    public function test_a_distributed_command_consumed_by_another_application_recorded_together_is_a_sub_flow_of_the_distributed_send(): void
    {
        $tickets = new class () {
            #[Distributed]
            #[CommandHandler('ticket.create', endpointId: 'ticket.creation')]
            public function create(string $ticket): void
            {
            }
        };
        $channel = SimpleMessageChannelBuilder::createQueueChannel('distributed_tickets');
        $recorder = new InMemorySpanRecorder();
        $users = EcotoneLite::bootstrapFlowTesting([], self::recordingSpansInto($recorder), [$channel, DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ticket_service', channelName: 'distributed_tickets'), ServiceConfiguration::createWithDefaults()->withServiceName('user_service'), self::tracingConfiguration()->withExecutionRecording()], enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE);
        $ticketService = EcotoneLite::bootstrapFlowTesting([$tickets::class], [$tickets, ...self::recordingSpansInto($recorder)], [$channel, ServiceConfiguration::createWithDefaults()->withServiceName('ticket_service'), self::tracingConfiguration()->withExecutionRecording()], enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE);

        $users->getDistributedBus()->sendCommand('ticket_service', 'ticket.create', 'User changed billing address');
        $ticketService->run('distributed_tickets', ExecutionPollingMetadata::createWithTestingSetup());
        $processes = FlowExecution::of($recorder->recordedSpans())->processes;

        self::assertSame(['send distributed, DistributedOutboundRouter::route', 'receive distributed_tickets, DistributedMessageHandler::handle'], array_column($processes, 'process'));
        self::assertSame(1, $processes[1]['sentBy']['process']);
    }

    public function test_the_default_text_prints_each_sub_flow_under_the_flow_that_sent_its_message_and_keeps_the_run_order_numbers(): void
    {
        $chain = new class () {
            #[CommandHandler('order.place')]
            public function place(array $order, EventBus $eventBus): void
            {
                $eventBus->publishWithRouting('order.placed', $order);
            }

            #[Asynchronous('notifications')]
            #[EventHandler('order.placed', endpointId: 'notify')]
            public function notify(array $order, EventBus $eventBus): void
            {
                $eventBus->publishWithRouting('customer.notified', $order);
            }

            #[Asynchronous('notifications')]
            #[EventHandler('customer.notified', endpointId: 'mail')]
            public function mail(array $order): void
            {
            }
        };

        $lines = $this->linesOf([$chain, $this->auditing()], function (FlowTestSupport $ecotone): void {
            $ecotone->sendCommandWithRouting('order.place', []);
            $ecotone->run('notifications', ExecutionPollingMetadata::createWithTestingSetup());
        });

        self::assertSame(
            [
                'process 1: send command order.place, class@anonymous::place',
                '  sub-flow process 2: receive notifications, class@anonymous::notify',
                '    sub-flow process 4: receive notifications, class@anonymous::mail',
                '  sub-flow process 3: receive notifications, class@anonymous::audit',
            ],
            array_values(array_filter($lines, fn (string $line): bool => preg_match('/^ *(sub-flow )?process \d+: /', $line) === 1)),
        );
        $notify = (int) array_search('  sub-flow process 2: receive notifications, class@anonymous::notify', $lines, true);
        self::assertSame(['    receive notifications', '      ↳ sent by: process 1, the event order.placed'], array_slice($lines, $notify + 1, 2));
    }

    /**
     * @param list<object> $handlers
     * @return list<string>
     */
    private function linesOf(array $handlers, callable $run): array
    {
        $recorder = new InMemorySpanRecorder();
        $run(EcotoneLite::bootstrapFlowTesting(
            array_map(fn (object $handler): string => $handler::class, $handlers),
            [...$handlers, ...self::recordingSpansInto($recorder)],
            [SimpleMessageChannelBuilder::createQueueChannel('notifications'), self::tracingConfiguration()->withExecutionRecording()],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        ));

        return FlowExecution::of($recorder->recordedSpans())->lines();
    }

    /**
     * @param list<object> $handlers
     * @param list<object> $extensionObjects
     * @return list<array<string, mixed>>
     */
    private function recorded(array $handlers, callable $run, array $extensionObjects = []): array
    {
        $extensionObjects = $extensionObjects === [] ? [SimpleMessageChannelBuilder::createQueueChannel('notifications')] : $extensionObjects;
        $recorder = new InMemorySpanRecorder();
        $run(EcotoneLite::bootstrapFlowTesting(
            array_map(fn (object $handler): string => $handler::class, $handlers),
            [...$handlers, ...self::recordingSpansInto($recorder)],
            [...$extensionObjects, self::tracingConfiguration()->withExecutionRecording()],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        ));

        return FlowExecution::of($recorder->recordedSpans())->processes;
    }

    private function ordering(): object
    {
        return new class () {
            #[CommandHandler('order.place')]
            public function place(array $order, EventBus $eventBus): void
            {
                $eventBus->publishWithRouting('order.placed', $order);
            }

            #[Asynchronous('notifications')]
            #[EventHandler('order.placed', endpointId: 'notify')]
            public function notify(array $order): void
            {
            }
        };
    }

    private function auditing(): object
    {
        return new class () {
            #[Asynchronous('notifications')]
            #[EventHandler('order.placed', endpointId: 'audit')]
            public function audit(array $order): void
            {
            }
        };
    }
}
