<?php

declare(strict_types=1);

namespace Test\Ecotone\Tracing;

use Ecotone\Api\Attribute\Around;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Gathered;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\Splitter;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\InstantRetryConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\DistributedBusHeader;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Interceptor\MethodInvocation;
use Ecotone\Api\Interceptor\Precedence;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\GatheredParts;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Test\Flow\FlowScenarioCatalogue;
use Ecotone\Test\Flow\Snapshot\FlowExecution;
use Ecotone\Test\LicenceTesting;
use RuntimeException;

/**
 * licence Apache-2.0
 * @internal
 */
final class ExecutionRecordingExplanationTest extends TracingTestCase
{
    public function test_the_gatherer_takes_in_a_part_by_its_headers_records_it_as_arrived_and_hands_the_full_group_to_the_gathered_handler(): void
    {
        $lines = array_map(trim(...), self::executionOf('tag-partitioned projection backfilled asynchronously in two batches'));

        $expected = [
            'process 5: receive backfill, GatheringMessageHandler::handle (part 1 of 2)',
            'handler GatheringMessageHandler::handle',
            '↳ chosen by: licence, over RequestReplyProducer',
            'read part 1 of 2',
            'record part 1 of 2 in InMemoryGatheredGroupStore',
            '↳ outcome: part stored, 1 of 2 in, group waits',
            'process 6: receive backfill, GatheringMessageHandler::handle (part 2 of 2)',
            'read part 2 of 2',
            'record part 2 of 2 in InMemoryGatheredGroupStore',
            '↳ outcome: part stored, 2 of 2 in, group released',
            '↳ runs in: this consumer run, gatherer called back',
            'release group',
            '↳ chosen by: all parts accounted for',
            '↳ outcome: GatheredParts, 2 of 2 parts, original payload array',
            '↳ runs in: this consumer run, no queue',
            'handler ProjectionRunCompletion::complete',
        ];

        self::assertSame($expected, self::inOrderOf($lines, $expected));
    }

    public function test_the_gatherer_steps_nest_as_the_code_calls_them(): void
    {
        $lines = self::executionOf('tag-partitioned projection backfilled asynchronously in two batches');
        $read = self::positionOf($lines, 'read part 2 of 2');
        $recorded = self::positionOf($lines, 'record part 2 of 2 in InMemoryGatheredGroupStore');
        $release = self::positionOf($lines, 'release group');

        self::assertSame(self::depthOf($lines[$read]), self::depthOf($lines[$recorded]));
        self::assertSame(self::depthOf($lines[$recorded]) + 1, self::depthOf($lines[$release]));
        self::assertSame(self::depthOf($lines[$release]) + 1, self::depthOf($lines[self::positionOf($lines, 'handler ProjectionRunCompletion::complete')]));
    }

    public function test_a_consumer_process_names_the_message_it_received_and_the_earlier_process_that_put_it_on_the_channel(): void
    {
        $lines = array_map(trim(...), self::executionOf('tag-partitioned projection backfilled asynchronously in two batches'));

        self::assertSame(['receive backfill', '↳ sent by: process 2, the message the entrypoint sent to backfill'], array_slice($lines, self::positionOf($lines, 'process 3: receive backfill, PartitionBatchExecutorHandler::executeBatch (1 of 2)') + 1, 2));
        self::assertSame(['receive backfill', '↳ sent by: process 3, the part report for part 1 of 2'], array_slice($lines, self::positionOf($lines, 'process 5: receive backfill, GatheringMessageHandler::handle (part 1 of 2)') + 1, 2));
    }

    public function test_the_step_that_put_a_message_on_the_channel_points_to_the_later_consumer_run_that_took_it(): void
    {
        $lines = array_map(trim(...), self::executionOf('tag-partitioned projection backfilled asynchronously in two batches'));

        self::assertSame(
            ['↳ taken by: process 3, a separate consumer run', '↳ taken by: process 4, a separate consumer run', '↳ taken by: process 5, a separate consumer run', '↳ taken by: process 6, a separate consumer run'],
            array_values(array_filter($lines, fn (string $line): bool => str_starts_with($line, '↳ taken by: '))),
        );
        $release = self::positionOf($lines, 'process 5: receive backfill, GatheringMessageHandler::handle (part 1 of 2)');
        self::assertContains('release collected message to backfill', array_slice($lines, 0, $release));
    }

    public function test_a_step_is_a_short_label_and_what_decided_the_next_channel_is_a_critical_hint_under_it(): void
    {
        $lines = $this->asynchronousOrder();
        $forward = self::positionOf($lines, 'forward to order.place.endpoint.target.execute');

        self::assertSame(self::depthOf($lines[$forward]) + 1, self::depthOf($lines[$forward + 1]));
        self::assertSame(['↳ chosen by: routing slip', '↳ routing slip: order.place.endpoint.target.execute → none'], array_map(trim(...), array_slice($lines, $forward + 1, 2)));
    }

    public function test_hints_that_do_not_decide_the_flow_are_printed_only_when_every_hint_is_asked_for(): void
    {
        self::assertSame([], preg_grep('/↳ headers added: /', $this->asynchronousOrder()));
        self::assertNotEmpty(preg_grep('/↳ headers added: traceparent, ecotone_tracing_delivery$/', $this->asynchronousOrder(withEveryHint: true)));
    }

    public function test_a_gatherer_process_is_titled_by_what_it_received_and_its_handler_and_its_first_step_says_where_the_work_ran(): void
    {
        $lines = array_map(trim(...), self::executionOf('tag-partitioned projection backfilled asynchronously in two batches'));
        $process = self::positionOf($lines, 'process 5: receive backfill, GatheringMessageHandler::handle (part 1 of 2)');

        self::assertSame(
            ['receive backfill', '↳ sent by: process 3, the part report for part 1 of 2', '↳ work in: process 3, part 1 of 2'],
            array_slice($lines, $process + 1, 3),
        );
    }

    public function test_a_part_the_store_ignores_is_recorded_as_ignored_not_as_waiting(): void
    {
        $handler = new class () {
            #[CommandHandler('split', outputChannelName: 'parts')]
            public function start(array $parts): array
            {
                return $parts;
            }

            #[Splitter('parts', endpointId: 'split.parts', outputChannelName: 'work', gatherChannelName: 'summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[Asynchronous('workers')]
            #[InternalHandler('work', endpointId: 'work.handler')]
            public function work(int $part): int
            {
                return $part;
            }

            #[Around(precedence: Precedence::DATABASE_TRANSACTION_PRECEDENCE - 1, pointcut: AsynchronousRunningEndpoint::class)]
            public function duplicateWorkerDelivery(MethodInvocation $invocation, AsynchronousRunningEndpoint $endpoint): mixed
            {
                $again = $invocation->cloneCurrentState();
                $result = $invocation->proceed();
                if ($endpoint->getEndpointId() === 'workers') {
                    $again->proceed();
                }

                return $result;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('summary', endpointId: 'summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
            }
        };

        $lines = array_map(trim(...), $this->recorded([$handler::class], [$handler], [SimpleMessageChannelBuilder::createQueueChannel('summaries'), SimpleMessageChannelBuilder::createQueueChannel('workers')], function (FlowTestSupport $ecotone): void {
            $ecotone->sendCommandWithRouting('split', [10, 20]);
            $ecotone->run('workers')->run('summaries');
        }));

        self::assertSame(
            [
                '↳ outcome: part stored, 1 of 2 in, group waits',
                '↳ outcome: duplicate part ignored',
                '↳ outcome: part stored, 2 of 2 in, group released',
                '↳ outcome: duplicate part ignored, group already released',
            ],
            array_map(fn (int $position): string => $lines[$position + 1], array_keys(array_filter($lines, fn (string $line): bool => preg_match('/^record part \d of 2 in InMemoryGatheredGroupStore$/', $line) === 1))),
        );
    }

    public function test_the_routing_slip_hint_shows_the_slip_the_handler_returned_and_what_the_forward_left(): void
    {
        $lines = array_map(trim(...), self::executionOf('orchestrator gateway starting the flow'));
        $forward = self::positionOf($lines, 'forward to approval.validate');

        self::assertSame(['↳ chosen by: routing slip', '↳ routing slip: approval.validate, approval.notify → approval.notify'], array_slice($lines, $forward + 1, 2));
    }

    public function test_a_routing_slip_the_output_channel_leaves_unchanged_is_kept_not_shown_as_changed(): void
    {
        $lines = array_map(trim(...), self::executionOf('failure is retried with a delay, then sent to the dead letter'));
        $forward = self::positionOf($lines, 'forward to dead_letter');

        self::assertSame(['↳ chosen by: output channel of DelayedRetryErrorHandler::handle', '↳ kept: routing slip order.placing.target.execute'], array_slice($lines, $forward + 1, 2));
        self::assertSame([], preg_grep('/^↳ routing slip: (.+) → \1$/', $lines));
    }

    public function test_each_partition_batch_send_says_which_gathering_part_it_carries(): void
    {
        $lines = array_map(trim(...), self::executionOf('tag-partitioned projection backfilled asynchronously in two batches'));

        self::assertSame(
            [
                '↳ group: part 1 of 2 of {generated#1}, reports to ecotone.projection.backfill.completion:course_seats',
                '↳ group: part 2 of 2 of {generated#1}, reports to ecotone.projection.backfill.completion:course_seats',
            ],
            array_values(array_map(fn (int $position): string => $lines[$position + 2], array_keys($lines, 'entrypoint sends to backfill', true))),
        );
    }

    public function test_what_decides_the_flow_of_a_projection_batch_is_shown_by_default(): void
    {
        $lines = array_map(trim(...), self::executionOf('tag-partitioned projection backfilled asynchronously in two batches'));

        self::assertContains('↳ service: GatheredPartitionBatchDispatcher, for backfill', $lines);
        self::assertContains('↳ found: 0 events', $lines);
        self::assertContains('↳ found: nothing', $lines);
        self::assertSame('↳ chosen by: licence, over RequestReplyProducer', $lines[self::positionOf($lines, 'handler GatheringMessageHandler::handle') + 1]);
    }

    public function test_a_failure_is_shown_where_it_was_thrown_and_not_on_every_step_it_passes(): void
    {
        $lines = array_map(trim(...), self::executionOf('event forwarded to a channel whose other interceptor fails after the send'));

        self::assertCount(1, array_keys($lines, '↳ failure: throws RuntimeException', true));
        self::assertSame('↳ failure: throws RuntimeException', $lines[self::positionOf($lines, 'release collected message to notifications') + 1]);
        self::assertContains('↳ failure: passes RuntimeException out of the process', $lines);
    }

    public function test_a_step_without_a_hand_over_names_whose_result_it_was(): void
    {
        $lines = array_map(trim(...), $this->asynchronousOrder());
        $route = self::positionOf($lines, 'route to order.place.endpoint.target');
        $noHandOver = array_values(array_filter(array_keys($lines, 'no hand-over', true), fn (int $position): bool => $position > $route));

        self::assertSame('↳ outcome: nothing returned by the router', $lines[$noHandOver[0] + 1]);
        self::assertSame('↳ outcome: nothing returned by class@anonymous::place', $lines[$noHandOver[1] + 1]);
    }

    public function test_a_send_to_a_direct_channel_runs_its_subscriber_inline_and_a_send_to_a_queue_puts_it_on_the_queue(): void
    {
        $lines = array_map(trim(...), $this->asynchronousOrder());

        self::assertContains('deliver inline to order.place.endpoint.target', $lines);
        self::assertNotContains('put on channel order.place.endpoint.target', $lines);
        self::assertContains('put on channel orders', $lines);
    }

    public function test_a_collected_message_is_held_on_its_first_send_and_released_after_the_handler_without_naming_the_collector_class(): void
    {
        $lines = array_map(trim(...), $this->asynchronousOrder());

        self::assertSame('↳ collected: held until the handler ends', $lines[self::positionOf($lines, 'before send MessageCollectorChannelInterceptor::preSend') + 1]);
        self::assertSame('↳ collected: released after the handler ended', $lines[self::positionOf($lines, 'release collected message to orders') + 1]);
        self::assertSame([], preg_grep('/^↳ (stopped|collected): .*MessageCollector/', $lines));
    }

    public function test_projecting_an_event_is_a_step_that_holds_the_route_to_its_handler(): void
    {
        $lines = self::executionOf('tag-partitioned projection backfilled asynchronously in two batches');
        $project = self::positionOf($lines, 'project FlowCourseDefined');

        self::assertSame('route to course_seats.defined.target', trim($lines[$project + 1]));
        self::assertSame(self::depthOf($lines[$project]) + 1, self::depthOf($lines[$project + 1]));
    }

    public function test_a_second_attempt_of_an_instant_retry_says_it_is_a_retry_and_why(): void
    {
        $orders = new class () {
            public int $attempts = 0;

            #[CommandHandler('order.place')]
            public function place(): void
            {
                if (++$this->attempts === 1) {
                    throw new RuntimeException('first attempt fails');
                }
            }
        };

        $lines = array_map(trim(...), $this->recorded([$orders::class], [$orders], [InstantRetryConfiguration::createWithDefaults()->withCommandBusRetry(true, 2)], fn (FlowTestSupport $ecotone) => $ecotone->sendCommandWithRouting('order.place', [])));

        self::assertContains('↳ retry: attempt 2 after RuntimeException, by InstantRetryInterceptor::retry', $lines);
    }

    public function test_a_distributed_send_shows_the_routing_slip_its_gateway_set_and_why_the_router_chose_the_channel(): void
    {
        $lines = array_map(trim(...), self::executionOf('distributed command consumed by the target service'));

        self::assertContains('↳ routing slip: none → ' . DistributedBusHeader::DISTRIBUTED_ROUTING_SLIP_VALUE, array_slice($lines, 0, 4));
        self::assertSame('↳ chosen by: service map, command to ticket_service', $lines[self::positionOf($lines, 'route to distributed_channel') + 1]);
    }

    public function test_no_step_names_a_class_of_the_recording(): void
    {
        $lines = self::executionOf('tag-partitioned projection backfilled asynchronously in two batches');

        self::assertSame([], array_values(array_filter($lines, fn (string $line): bool => preg_match('/\bRecorded[A-Z]\w*\b/', $line) === 1)));
    }

    public function test_after_the_put_each_after_send_phase_names_its_interceptor_method_and_when_it_runs_and_inherited_empty_phases_are_left_out(): void
    {
        $lines = array_map(trim(...), $this->asynchronousOrder(withEveryHint: true));
        $release = self::positionOf($lines, 'release collected message to orders');

        self::assertSame(
            [
                'after send SendRetryChannelInterceptor::afterSendCompletion',
                'after send TracingChannelInterceptor::afterSendCompletion',
                'after receive TracingChannelInterceptor::afterReceiveCompletion',
            ],
            array_values(array_filter(array_slice($lines, $release), fn (string $line): bool => preg_match('/^after (send|successful send|receive|take) /', $line) === 1)),
        );
        self::assertSame(['↳ failure: none'], array_values(array_unique(array_filter(array_slice($lines, $release, 20), fn (string $line): bool => str_starts_with($line, '↳ failure: ')))));
    }

    public function test_a_send_nests_each_interceptor_around_the_next_with_the_put_on_the_channel_innermost(): void
    {
        $lines = $this->asynchronousOrder();
        $release = self::positionOf($lines, 'release collected message to orders');

        self::assertSame(
            [
                [0, 'release collected message to orders'],
                [1, '↳ collected: released after the handler ended'],
                [1, '↳ taken by: process 2, a separate consumer run'],
                [1, 'before send OutboundSerializationChannelInterceptor::preSend'],
                [2, 'before send MessageCollectorChannelInterceptor::preSend'],
                [3, 'before send InMemoryQueueAcknowledgeInterceptor::preSend'],
                [4, 'before send TracingChannelInterceptor::preSend'],
                [5, 'span send orders'],
                [6, 'put on channel orders'],
                [1, 'after send SendRetryChannelInterceptor::afterSendCompletion'],
                [1, 'after send TracingChannelInterceptor::afterSendCompletion'],
            ],
            array_map(fn (string $line): array => [self::depthOf($line) - self::depthOf($lines[$release]), trim($line)], array_slice($lines, $release, 11)),
        );
    }

    public function test_the_send_the_collector_holds_stops_at_the_collector_and_never_reaches_the_channel(): void
    {
        $lines = $this->asynchronousOrder();
        $send = self::positionOf($lines, 'send orders');

        self::assertSame(
            [
                [0, 'send orders'],
                [1, 'before send OutboundSerializationChannelInterceptor::preSend'],
                [2, 'before send MessageCollectorChannelInterceptor::preSend'],
                [3, '↳ collected: held until the handler ends'],
            ],
            array_map(fn (string $line): array => [self::depthOf($line) - self::depthOf($lines[$send]), trim($line)], array_slice($lines, $send, 4)),
        );
        self::assertLessThanOrEqual(self::depthOf($lines[$send]), self::depthOf($lines[$send + 4]));
    }

    public function test_the_receive_takes_the_message_from_the_channel_and_names_the_after_receive_phase_that_ran(): void
    {
        $lines = array_map(trim(...), $this->asynchronousOrder());
        $receive = self::positionOf($lines, 'process 2: receive orders, class@anonymous::place') + 1;

        self::assertSame(
            ['receive orders', '↳ sent by: process 1, the command order.place', 'take from channel orders', 'after receive TracingChannelInterceptor::afterReceiveCompletion'],
            array_slice($lines, $receive, 4),
        );
    }

    public function test_the_consumer_forwards_by_the_routing_slip_saying_what_the_slip_held_and_what_remains(): void
    {
        $lines = array_map(trim(...), $this->asynchronousOrder());
        $forward = self::positionOf($lines, 'forward to order.place.endpoint.target.execute');

        self::assertSame('bridge Bridge::handle', $lines[$forward - 1]);
        self::assertSame(['↳ chosen by: routing slip', '↳ routing slip: order.place.endpoint.target.execute → none', 'direct channel order.place.endpoint.target.execute'], array_slice($lines, $forward + 1, 3));
    }

    public function test_the_routing_slip_an_asynchronous_endpoint_puts_on_the_message_is_recorded_before_the_send_to_the_queue(): void
    {
        $lines = array_map(trim(...), $this->asynchronousOrder());
        $forward = self::positionOf($lines, 'forward to orders');

        self::assertSame('handler RoutingSlipPrepender::transform', $lines[$forward - 1]);
        self::assertSame(['↳ chosen by: output channel of RoutingSlipPrepender::transform', '↳ routing slip: none → order.place.endpoint.target.execute', 'send orders'], array_slice($lines, $forward + 1, 3));
    }

    public function test_a_result_forwarded_by_the_output_channel_and_a_reply_to_the_waiting_sender_name_their_mechanism(): void
    {
        $orders = new class () {
            #[CommandHandler('order.place', outputChannelName: 'order.confirm')]
            public function place(string $order): string
            {
                return $order;
            }

            #[InternalHandler('order.confirm')]
            public function confirm(string $order): string
            {
                return $order . ' confirmed';
            }
        };

        $lines = array_map(trim(...), $this->recorded([$orders::class], [$orders], [], fn (FlowTestSupport $ecotone) => $ecotone->sendCommandWithRouting('order.place', 'order-1')));

        $forward = self::positionOf($lines, 'forward to order.confirm');
        self::assertSame('↳ chosen by: output channel of class@anonymous::place', $lines[$forward + 1]);
        self::assertContains('reply to the reply channel of the request', $lines);
    }

    public function test_nothing_handed_over_says_whether_the_handler_returned_nothing_or_nothing_took_its_result(): void
    {
        $listeners = new class () {
            #[EventHandler('order.placed')]
            public function audit(): string
            {
                return 'audited';
            }

            #[EventHandler('order.placed')]
            public function notify(): void
            {
            }
        };

        $lines = array_map(trim(...), $this->recorded([$listeners::class], [$listeners], [], fn (FlowTestSupport $ecotone) => $ecotone->publishEventWithRouting('order.placed', [])));

        $whyNothing = array_values(array_map(fn (int $position): string => $lines[$position + 1], array_keys($lines, 'no hand-over', true)));
        self::assertContains('↳ outcome: string returned by class@anonymous::audit, not taken', $whyNothing);
        self::assertContains('↳ outcome: nothing returned by class@anonymous::notify', $whyNothing);
    }

    public function test_the_entrypoint_sets_the_routing_slip_and_its_router_says_which_header_chose_the_route(): void
    {
        $lines = array_map(trim(...), self::executionOf('tag-partitioned projection backfilled asynchronously in two batches'));
        $entrypoint = self::positionOf($lines, 'entrypoint sends to backfill');

        self::assertSame('↳ routing slip: none → ecotone.projection.partition_batch.executor', $lines[$entrypoint + 1]);
        self::assertSame(['route to backfill', '↳ chosen by: header ecotone.messaging.entrypoint'], array_slice($lines, $entrypoint + 5, 2));
    }

    public function test_copies_of_one_event_sent_to_two_asynchronous_handlers_are_each_released_and_linked_to_their_own_consumer(): void
    {
        $listeners = new class () {
            #[CommandHandler('order.place')]
            public function place(array $order, EventBus $eventBus): void
            {
                $eventBus->publishWithRouting('order.placed', []);
            }

            #[Asynchronous('notifications')]
            #[EventHandler('order.placed', endpointId: 'audit')]
            public function audit(): void
            {
            }

            #[Asynchronous('notifications')]
            #[EventHandler('order.placed', endpointId: 'notify')]
            public function notify(): void
            {
            }
        };

        $lines = array_map(trim(...), $this->recorded([$listeners::class], [$listeners], [SimpleMessageChannelBuilder::createQueueChannel('notifications')], function (FlowTestSupport $ecotone): void {
            $ecotone->sendCommandWithRouting('order.place', []);
            $ecotone->run('notifications', ExecutionPollingMetadata::createWithTestingSetup());
        }));

        self::assertSame(
            [['↳ collected: released after the handler ended', '↳ taken by: process 2, a separate consumer run'], ['↳ collected: released after the handler ended', '↳ taken by: process 3, a separate consumer run']],
            array_map(fn (int $position): array => array_slice($lines, $position + 1, 2), array_keys($lines, 'release collected message to notifications', true)),
        );
        self::assertSame(
            ['↳ sent by: process 1, the event order.placed', '↳ sent by: process 1, the event order.placed'],
            array_map(fn (int $position): string => $lines[$position + 1], array_keys($lines, 'receive notifications', true)),
        );
    }

    /**
     * @return list<string>
     */
    private function asynchronousOrder(bool $withEveryHint = false): array
    {
        $orders = new class () {
            #[Asynchronous('orders')]
            #[CommandHandler('order.place', endpointId: 'order.place.endpoint')]
            public function place(string $order): void
            {
            }
        };

        return $this->recorded([$orders::class], [$orders], [SimpleMessageChannelBuilder::createQueueChannel('orders')], function (FlowTestSupport $ecotone): void {
            $ecotone->sendCommandWithRouting('order.place', 'order-1');
            $ecotone->run('orders', ExecutionPollingMetadata::createWithTestingSetup());
        }, $withEveryHint);
    }

    /**
     * @param list<string> $lines
     */
    private static function positionOf(array $lines, string $trimmed): int
    {
        $position = array_search($trimmed, array_map(trim(...), $lines), true);
        self::assertIsInt($position, "No step {$trimmed}");

        return $position;
    }

    private static function depthOf(string $line): int
    {
        return intdiv(strspn($line, ' '), 2);
    }

    /**
     * @param list<string> $lines
     * @param list<string> $expected
     * @return list<string>
     */
    private static function inOrderOf(array $lines, array $expected): array
    {
        $found = [];
        foreach ($lines as $line) {
            if (($expected[count($found)] ?? null) === $line) {
                $found[] = $line;
            }
        }

        return $found;
    }

    /**
     * @param list<class-string> $classes
     * @param list<object> $services
     * @param list<object> $extensionObjects
     * @param callable(FlowTestSupport): void $run
     * @return list<string>
     */
    private function recorded(array $classes, array $services, array $extensionObjects, callable $run, bool $withEveryHint = false): array
    {
        $recorder = new InMemorySpanRecorder();
        $run(EcotoneLite::bootstrapFlowTesting(
            $classes,
            [...$services, ...self::recordingSpansInto($recorder)],
            [...$extensionObjects, self::tracingConfiguration()->withExecutionRecording()],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        ));

        return FlowExecution::of($recorder->recordedSpans())->lines($withEveryHint);
    }

    /**
     * @return list<string>
     */
    private static function executionOf(string $scenarioName): array
    {
        foreach (FlowScenarioCatalogue::all() as $scenario) {
            if ($scenario->name !== $scenarioName) {
                continue;
            }
            $recorder = new InMemorySpanRecorder();
            $ecotone = EcotoneLite::bootstrapFlowTesting(
                $scenario->classesToResolve,
                [...$scenario->services, ...self::recordingSpansInto($recorder)],
                [...$scenario->extensionObjects, self::tracingConfiguration()->withExecutionRecording()],
                enabledModules: [],
                licenceKey: LicenceTesting::VALID_LICENCE,
            );
            foreach (array_keys($scenario->stages) as $stage) {
                self::assertNull($scenario->runStage($stage, $ecotone));
            }

            return FlowExecution::of($recorder->recordedSpans())->lines();
        }
        self::fail("No scenario named {$scenarioName} in the flow scenario catalogue");
    }
}
