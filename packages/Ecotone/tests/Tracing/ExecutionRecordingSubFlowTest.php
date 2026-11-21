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
use Ecotone\Api\Gateway\DistributedBusHeader;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Api\Tracing\RecordedProcess;
use Ecotone\Api\Tracing\Span;
use Ecotone\Api\Tracing\SpanAttribute;
use Ecotone\Api\Tracing\TracingConfiguration;
use Ecotone\Api\Tracing\TracingLevel;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\DataProvider;
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
            $ecotone->run('notifications', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
        });

        self::assertNull($processes[0]->sentBy);
        self::assertSame(1, $processes[1]->sentBy->processNumber);
        self::assertSame('release collected message to notifications', $processes[0]->steps[$processes[1]->sentBy->stepNumber - 1]->label);
    }

    public function test_an_event_published_to_two_consumers_makes_each_a_sub_flow_of_its_own_send(): void
    {
        $processes = $this->recorded([$this->ordering(), $this->auditing()], function (FlowTestSupport $ecotone): void {
            $ecotone->sendCommandWithRouting('order.place', []);
            $ecotone->run('notifications', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
        });

        self::assertSame([1, 1], [$processes[1]->sentBy->processNumber, $processes[2]->sentBy->processNumber]);
        self::assertNotSame($processes[1]->sentBy->stepNumber, $processes[2]->sentBy->stepNumber);
        self::assertSame(
            ['release collected message to notifications', 'release collected message to notifications'],
            [$processes[0]->steps[$processes[1]->sentBy->stepNumber - 1]->label, $processes[0]->steps[$processes[2]->sentBy->stepNumber - 1]->label],
        );
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function consumingOrders(): iterable
    {
        yield 'notifications first' => [['notifications', 'audits']];
        yield 'audits first' => [['audits', 'notifications']];
    }

    /**
     * @param list<string> $consumingOrder
     */
    #[DataProvider('consumingOrders')]
    public function test_an_event_published_to_handlers_on_two_channels_makes_each_consumer_run_a_sub_flow_of_the_send_to_its_channel_in_either_consuming_order(array $consumingOrder): void
    {
        $handlers = new class () {
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

            #[Asynchronous('audits')]
            #[EventHandler('order.placed', endpointId: 'audit')]
            public function audit(array $order): void
            {
            }
        };

        $processes = $this->recorded([$handlers], function (FlowTestSupport $ecotone) use ($consumingOrder): void {
            $ecotone->sendCommandWithRouting('order.place', []);
            foreach ($consumingOrder as $channel) {
                $ecotone->run($channel, ExecutionPollingMetadata::createWithFinishWhenNoMessages());
            }
        }, [SimpleMessageChannelBuilder::createQueueChannel('notifications'), SimpleMessageChannelBuilder::createQueueChannel('audits')]);
        $sendOf = fn (string $consumer): string => $processes[0]->steps[array_values(array_filter($processes, fn (RecordedProcess $process): bool => str_starts_with($process->title, 'receive ' . $consumer)))[0]->sentBy->stepNumber - 1]->label;

        self::assertSame([1, 1], [$processes[1]->sentBy->processNumber, $processes[2]->sentBy->processNumber]);
        self::assertSame(['release collected message to notifications', 'release collected message to audits'], [$sendOf('notifications'), $sendOf('audits')]);
    }

    public function test_a_message_the_in_memory_queue_delivers_again_after_a_failure_is_each_time_a_sub_flow_of_the_send_that_put_it_on_the_channel(): void
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
            $ecotone->run('orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));
            $ecotone->run('orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
        }, [SimpleMessageChannelBuilder::createQueueChannel('orders')]);

        self::assertCount(3, $processes);
        self::assertSame(1, $processes[1]->sentBy->processNumber);
        self::assertEquals($processes[1]->sentBy, $processes[2]->sentBy);
    }

    public function test_a_process_whose_message_was_sent_outside_the_recording_is_a_flow(): void
    {
        $processes = $this->recorded([$this->ordering()], function (FlowTestSupport $ecotone): void {
            $ecotone->sendCommandWithRouting('order.place', [], metadata: [MessageHeaders::TRACEPARENT => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01']);
            $ecotone->run('notifications', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
        });

        self::assertNull($processes[0]->sentBy);
        self::assertSame(1, $processes[1]->sentBy->processNumber);
    }

    public function test_each_application_records_its_own_side_of_a_distributed_command_and_the_consumer_span_continues_the_trace_of_the_distributed_send(): void
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
        $users = EcotoneLite::bootstrapFlowTesting([], self::recordingSpansInto($recorder), [$channel, DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ticket_service', channelName: 'distributed_tickets'), ServiceConfiguration::createWithDefaults()->withServiceName('user_service'), self::tracingConfiguration()->withLevel(TracingLevel::Framework)], enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE);
        $ticketService = EcotoneLite::bootstrapFlowTesting([$tickets::class], [$tickets, ...self::recordingSpansInto($recorder)], [$channel, ServiceConfiguration::createWithDefaults()->withServiceName('ticket_service'), self::tracingConfiguration()->withLevel(TracingLevel::Framework)], enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE);

        $users->getDistributedBus()->sendCommand('ticket_service', 'ticket.create', 'User changed billing address');
        $ticketService->run('distributed_tickets', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
        $sent = $users->popExecutionRecording()->processes;
        $received = $ticketService->popExecutionRecording()->processes;
        [$send] = self::tracedSpansNamed($recorder, 'send distributed_tickets');
        [$receive] = self::tracedSpansNamed($recorder, 'receive distributed_tickets');

        self::assertSame(['send distributed, DistributedOutboundRouter::route'], array_map(fn (RecordedProcess $process): string => $process->title, $sent));
        self::assertSame(['receive distributed_tickets, DistributedMessageHandler::handle'], array_map(fn (RecordedProcess $process): string => $process->title, $received));
        self::assertNull($received[0]->sentBy);
        self::assertSame([$send->traceId, $send->spanId], [$receive->traceId, self::tracedParentIdOf($recorder->recordedSpans(), $receive)]);
    }

    public function test_two_applications_sharing_a_span_recorder_order_each_consumer_span_after_the_send_it_continues_even_when_the_sender_ran_more_than_the_consumer(): void
    {
        $tickets = new class () {
            #[Distributed]
            #[CommandHandler('ticket.create', endpointId: 'ticket.creation')]
            public function create(string $ticket): void
            {
            }
        };
        $registration = new class () {
            #[CommandHandler('user.register')]
            public function register(string $user): void
            {
            }
        };
        $channel = SimpleMessageChannelBuilder::createQueueChannel('distributed_tickets');
        $recorder = new InMemorySpanRecorder();
        $users = EcotoneLite::bootstrapFlowTesting([$registration::class], [$registration, ...self::recordingSpansInto($recorder)], [$channel, DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ticket_service', channelName: 'distributed_tickets'), ServiceConfiguration::createWithDefaults()->withServiceName('user_service'), self::tracingConfiguration()->withLevel(TracingLevel::Framework)], enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE);
        $ticketService = EcotoneLite::bootstrapFlowTesting([$tickets::class], [$tickets, ...self::recordingSpansInto($recorder)], [$channel, ServiceConfiguration::createWithDefaults()->withServiceName('ticket_service'), self::tracingConfiguration()->withLevel(TracingLevel::Framework)], enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE);

        $users->sendCommandWithRouting('user.register', 'user-1');
        $users->getDistributedBus()->sendCommand('ticket_service', 'ticket.create', 'User changed billing address');
        $users->getDistributedBus()->sendCommand('ticket_service', 'ticket.create', 'User changed shipping address');
        $ticketService->run('distributed_tickets', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
        $orderOf = fn (Span $span): int => (int) $span->attributes[SpanAttribute::EXECUTION_ORDER];
        $sends = [];
        foreach (self::tracedSpansNamed($recorder, 'send distributed_tickets') as $send) {
            $sends[$send->spanId] = $send;
        }
        $receives = self::tracedSpansNamed($recorder, 'receive distributed_tickets');

        self::assertCount(2, $receives);
        foreach ($receives as $receive) {
            self::assertGreaterThan($orderOf($sends[self::tracedParentIdOf($recorder->recordedSpans(), $receive)]), $orderOf($receive));
        }
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
            $ecotone->run('notifications', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
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
    public function test_a_distributed_command_from_another_application_is_a_process_of_its_own_whose_lines_name_the_sending_application_and_the_command(): void
    {
        $ticketService = $this->ticketServiceReceivingFrom(function (SimpleMessageChannelBuilder $channel): void {
            $users = EcotoneLite::bootstrapFlowTesting([], [], [$channel, DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ticket_service', channelName: 'distributed_tickets'), ServiceConfiguration::createWithDefaults()->withServiceName('user_service'), TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework)], enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE);
            $users->getDistributedBus()->sendCommand('ticket_service', 'ticket.create', 'User changed billing address');
        });

        $received = $ticketService->popExecutionRecording();

        self::assertCount(1, $received->processes);
        self::assertNull($received->processes[0]->sentBy);
        self::assertSame(['    ↳ sent by: application user_service, the command ticket.create'], self::sentByLinesOf($received->lines));
    }

    public function test_a_distributed_event_from_another_application_is_a_process_of_its_own_whose_lines_name_the_sending_application_and_the_event(): void
    {
        $ticketService = $this->ticketServiceReceivingFrom(function (SimpleMessageChannelBuilder $channel): void {
            $users = EcotoneLite::bootstrapFlowTesting([], [], [$channel, DistributedServiceMap::initialize()->withEventMapping(channelName: 'distributed_tickets', subscriptionKeys: ['user.*']), ServiceConfiguration::createWithDefaults()->withServiceName('user_service'), TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework)], enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE);
            $users->getDistributedBus()->publishEvent('user.billing_address_changed', 'User changed billing address');
        });

        $received = $ticketService->popExecutionRecording();

        self::assertCount(1, $received->processes);
        self::assertNull($received->processes[0]->sentBy);
        self::assertSame(['    ↳ sent by: application user_service, the event user.billing_address_changed'], self::sentByLinesOf($received->lines));
    }

    public function test_a_distributed_command_that_names_no_sending_service_is_recorded_as_sent_by_another_application(): void
    {
        $ticketService = $this->ticketServiceReceivingFrom(function (SimpleMessageChannelBuilder $channel): void {
            $sender = EcotoneLite::bootstrapFlowTesting([], [], [$channel, TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework)], enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE);
            $sender->sendDirectToChannel('distributed_tickets', 'User changed billing address', [
                MessageHeaders::CONTENT_TYPE => 'text/plain',
                MessageHeaders::ROUTING_SLIP => DistributedBusHeader::DISTRIBUTED_ROUTING_SLIP_VALUE,
                DistributedBusHeader::DISTRIBUTED_PAYLOAD_TYPE => 'command',
                DistributedBusHeader::DISTRIBUTED_ROUTING_KEY => 'ticket.create',
            ]);
        });

        $received = $ticketService->popExecutionRecording();

        self::assertNull($received->processes[0]->sentBy);
        self::assertSame(['    ↳ sent by: another application, the command ticket.create'], self::sentByLinesOf($received->lines));
    }

    public function test_a_distributed_command_sent_and_consumed_inside_one_application_is_a_sub_flow_of_its_send_with_no_application_named(): void
    {
        $tickets = self::ticketCreation();
        $ticketService = EcotoneLite::bootstrapFlowTesting([$tickets::class], [$tickets], [SimpleMessageChannelBuilder::createQueueChannel('distributed_tickets'), DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ticket_service', channelName: 'distributed_tickets'), ServiceConfiguration::createWithDefaults()->withServiceName('ticket_service'), TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework)], enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE);

        $ticketService->getDistributedBus()->sendCommand('ticket_service', 'ticket.create', 'User changed billing address');
        $ticketService->run('distributed_tickets', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
        $recording = $ticketService->popExecutionRecording();

        self::assertSame(1, $recording->processes[1]->sentBy?->processNumber);
        self::assertSame(['      ↳ sent by: process 1, the message'], self::sentByLinesOf($recording->lines));
    }

    private function ticketServiceReceivingFrom(callable $send): FlowTestSupport
    {
        $tickets = self::ticketCreation();
        $channel = SimpleMessageChannelBuilder::createQueueChannel('distributed_tickets');
        $ticketService = EcotoneLite::bootstrapFlowTesting([$tickets::class], [$tickets], [$channel, ServiceConfiguration::createWithDefaults()->withServiceName('ticket_service'), TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework)], enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE);
        $send($channel);
        $ticketService->run('distributed_tickets', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        return $ticketService;
    }

    private static function ticketCreation(): object
    {
        return new class () {
            #[Distributed]
            #[CommandHandler('ticket.create', endpointId: 'ticket.creation')]
            public function create(string $ticket): void
            {
            }

            #[Distributed]
            #[EventHandler('user.billing_address_changed', endpointId: 'ticket.billing_address_noted')]
            public function noteBillingAddress(string $event): void
            {
            }
        };
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private static function sentByLinesOf(array $lines): array
    {
        return array_values(array_filter($lines, fn (string $line): bool => str_contains($line, '↳ sent by: ')));
    }

    private function linesOf(array $handlers, callable $run): array
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            array_map(fn (object $handler): string => $handler::class, $handlers),
            [...$handlers],
            [SimpleMessageChannelBuilder::createQueueChannel('notifications'), TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework)],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $run($ecotone);

        return $ecotone->popExecutionRecording()->lines;
    }

    /**
     * @param list<object> $handlers
     * @param list<object> $extensionObjects
     * @return list<RecordedProcess>
     */
    private function recorded(array $handlers, callable $run, array $extensionObjects = []): array
    {
        $extensionObjects = $extensionObjects === [] ? [SimpleMessageChannelBuilder::createQueueChannel('notifications')] : $extensionObjects;
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            array_map(fn (object $handler): string => $handler::class, $handlers),
            [...$handlers],
            [...$extensionObjects, TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework)],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $run($ecotone);

        return $ecotone->popExecutionRecording()->processes;
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

    /**
     * @return list<Span>
     */
    private static function tracedSpansNamed(InMemorySpanRecorder $recorder, string $name): array
    {
        return array_values(array_filter($recorder->recordedSpans(), fn (Span $span): bool => $span->name === $name && ! isset($span->attributes[SpanAttribute::EXECUTION_STEP])));
    }
}
