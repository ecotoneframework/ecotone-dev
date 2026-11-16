<?php

declare(strict_types=1);

namespace Test\Ecotone\Tracing;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Test\Flow\FlowScenarioCatalogue;
use Ecotone\Test\Flow\Snapshot\FlowExecution;
use Ecotone\Test\LicenceTesting;

/**
 * licence Apache-2.0
 * @internal
 */
final class ExecutionRecordingExplanationTest extends TracingTestCase
{
    public function test_the_gatherer_takes_in_a_part_by_its_headers_records_it_as_arrived_and_hands_the_full_group_to_the_gathered_handler(): void
    {
        $lines = self::executionOf('tag-partitioned projection backfilled asynchronously in two batches');

        $expected = [
            'process 5: receive backfill, handled by the gatherer only, recording part 1 of 2 reported by process 3, whose work ran there',
            'handler GatheringMessageHandler::handle, chosen by the licence',
            'read part 1 of 2 of gathering group {generated#1} from the headers ecotone_gathering_group_id, ecotone_gathering_part_number and ecotone_gathering_group_size',
            'record part 1 of gathering group {generated#1} as arrived, in InMemoryGatheredGroupStore::completePart: the group still waits for its other parts, so nothing is handed over and this consumer run ends here',
            'process 6: receive backfill, handled by the gatherer only, recording part 2 of 2 reported by process 4, whose work ran there',
            'handler GatheringMessageHandler::handle, chosen by the licence',
            'read part 2 of 2 of gathering group {generated#1} from the headers ecotone_gathering_group_id, ecotone_gathering_part_number and ecotone_gathering_group_size',
            'record part 2 of gathering group {generated#1} as arrived, in InMemoryGatheredGroupStore::completePart: with it all 2 parts are in, so the store releases the group and calls the gatherer back right here',
            'release 2 of 2 parts, as all parts are accounted for: build GatheredParts with 2 results and the original payload array and pass it straight to the handler the #[Gathered] endpoint wraps, inside this same consumer run, with no queue in between',
            'handler ProjectionRunCompletion::complete returns nothing',
        ];

        self::assertSame($expected, self::inOrderOf(array_map(trim(...), $lines), $expected));
    }

    public function test_the_gatherer_steps_nest_as_the_code_calls_them(): void
    {
        $lines = self::executionOf('tag-partitioned projection backfilled asynchronously in two batches');
        $recorded = self::positionOf($lines, 'record part 2 of gathering group {generated#1} as arrived, in InMemoryGatheredGroupStore::completePart: with it all 2 parts are in, so the store releases the group and calls the gatherer back right here');

        self::assertSame(self::depthOf($lines[$recorded - 1]), self::depthOf($lines[$recorded]));
        self::assertSame(self::depthOf($lines[$recorded]) + 1, self::depthOf($lines[$recorded + 1]));
        self::assertSame(self::depthOf($lines[$recorded + 1]) + 1, self::depthOf($lines[$recorded + 2]));
    }

    public function test_a_consumer_process_names_the_message_it_received_and_the_earlier_process_that_put_it_on_the_channel(): void
    {
        $lines = array_map(trim(...), self::executionOf('tag-partitioned projection backfilled asynchronously in two batches'));

        self::assertSame('receive backfill: the message the entrypoint sent to backfill, put on the channel by process 2', $lines[self::positionOf($lines, 'process 3: receive backfill, handled by PartitionBatchExecutorHandler::executeBatch (1 of 2)') + 1]);
        self::assertSame('receive backfill: the part report for part 1 of 2, put on the channel by process 3', $lines[self::positionOf($lines, 'process 5: receive backfill, handled by the gatherer only, recording part 1 of 2 reported by process 3, whose work ran there') + 1]);
    }

    public function test_the_step_that_put_a_message_on_the_channel_points_to_the_later_consumer_run_that_took_it(): void
    {
        $lines = array_map(trim(...), self::executionOf('tag-partitioned projection backfilled asynchronously in two batches'));

        self::assertContains('release collected message to backfill, held by MessageCollectorChannelInterceptor on its first send; taken from the channel later by a separate consumer run, process 5', $lines);
        self::assertContains('release collected message to backfill, held by MessageCollectorChannelInterceptor on its first send; taken from the channel later by a separate consumer run, process 6', $lines);
        self::assertContains('send backfill; taken from the channel later by a separate consumer run, process 3', $lines);
        self::assertContains('send backfill; taken from the channel later by a separate consumer run, process 4', $lines);
    }

    public function test_no_step_names_a_class_of_the_recording(): void
    {
        $lines = self::executionOf('tag-partitioned projection backfilled asynchronously in two batches');

        self::assertSame([], array_values(array_filter($lines, fn (string $line): bool => preg_match('/\bRecorded[A-Z]\w*\b/', $line) === 1)));
    }

    public function test_after_the_put_each_after_send_phase_names_its_interceptor_method_and_when_it_runs_and_inherited_empty_phases_are_left_out(): void
    {
        $lines = array_map(trim(...), $this->asynchronousOrder());
        $release = self::positionOf($lines, 'release collected message to orders, held by MessageCollectorChannelInterceptor on its first send; taken from the channel later by a separate consumer run, process 2');
        $afterPhases = array_values(array_filter(array_slice($lines, $release), fn (string $line): bool => str_contains($line, ', run ')));

        self::assertSame(
            [
                'SendRetryChannelInterceptor::afterSendCompletion, run when the send ended, here without a failure',
                'TracingChannelInterceptor::afterSendCompletion, run when the send ended, here without a failure',
                'TracingChannelInterceptor::afterReceiveCompletion, run when the take ended, here with a message and no failure',
            ],
            $afterPhases,
        );
        self::assertSame([], array_values(array_filter($lines, fn (string $line): bool => str_contains($line, ' through '))));
    }

    public function test_a_send_nests_each_interceptor_around_the_next_with_the_put_on_the_channel_innermost(): void
    {
        $lines = $this->asynchronousOrder();
        $release = self::positionOf($lines, 'release collected message to orders, held by MessageCollectorChannelInterceptor on its first send; taken from the channel later by a separate consumer run, process 2');

        self::assertSame(
            [
                [0, 'release collected message to orders, held by MessageCollectorChannelInterceptor on its first send; taken from the channel later by a separate consumer run, process 2'],
                [1, 'before send, OutboundSerializationChannelInterceptor::preSend'],
                [2, 'passes the message on unchanged'],
                [2, 'before send, MessageCollectorChannelInterceptor::preSend'],
                [3, 'passes the message on unchanged'],
                [3, 'before send, InMemoryQueueAcknowledgeInterceptor::preSend'],
                [4, 'passes on the message with headers added: ecotone_messaging_consumer_acknowledge_header, ecotone_in_memory_queue_consumer_acknowledge'],
                [4, 'before send, TracingChannelInterceptor::preSend'],
                [5, 'span send orders'],
                [6, 'passes on the message with headers added: traceparent, ecotone_tracing_delivery'],
                [6, 'put on channel orders'],
                [1, 'SendRetryChannelInterceptor::afterSendCompletion, run when the send ended, here without a failure'],
                [1, 'TracingChannelInterceptor::afterSendCompletion, run when the send ended, here without a failure'],
            ],
            array_map(fn (string $line): array => [self::depthOf($line) - self::depthOf($lines[$release]), trim($line)], array_slice($lines, $release, 13)),
        );
    }

    public function test_the_send_the_collector_holds_stops_at_the_collector_and_never_reaches_the_channel(): void
    {
        $lines = $this->asynchronousOrder();
        $send = self::positionOf($lines, 'send orders');

        self::assertSame(
            [
                [0, 'send orders'],
                [1, 'before send, OutboundSerializationChannelInterceptor::preSend'],
                [2, 'passes on the message with headers removed: ecotone_messaging_reply_channel'],
                [2, 'before send, MessageCollectorChannelInterceptor::preSend'],
                [3, 'collects the message and passes none on: the send stops here, so the interceptors after it and the put on the channel do not run; the message is held until the handler ends'],
            ],
            array_map(fn (string $line): array => [self::depthOf($line) - self::depthOf($lines[$send]), trim($line)], array_slice($lines, $send, 5)),
        );
        self::assertLessThanOrEqual(self::depthOf($lines[$send]), self::depthOf($lines[$send + 5]));
    }

    public function test_the_receive_takes_the_message_from_the_channel_and_names_the_after_receive_phase_that_ran(): void
    {
        $lines = array_map(trim(...), $this->asynchronousOrder());
        $receive = self::positionOf($lines, 'receive orders: the command order.place, put on the channel by process 1');

        self::assertSame(
            ['receive orders: the command order.place, put on the channel by process 1', 'take the message from channel orders', 'TracingChannelInterceptor::afterReceiveCompletion, run when the take ended, here with a message and no failure'],
            array_slice($lines, $receive, 3),
        );
    }

    public function test_the_consumer_forwards_by_the_routing_slip_saying_what_the_slip_held_and_what_remains(): void
    {
        $lines = array_map(trim(...), $this->asynchronousOrder());
        $forward = self::positionOf($lines, 'forward string by the routing slip, taking order.place.endpoint.target.execute from it; the slip held order.place.endpoint.target.execute and is now removed');

        self::assertSame('bridge Bridge::handle returns a message of string', $lines[$forward - 1]);
        self::assertSame('the forwarded message carries the string the handler returned, with the headers of the request', $lines[$forward + 1]);
        self::assertSame('direct channel order.place.endpoint.target.execute', $lines[$forward + 2]);
    }

    public function test_the_routing_slip_an_asynchronous_endpoint_puts_on_the_message_is_recorded_before_the_send_to_the_queue(): void
    {
        $lines = array_map(trim(...), $this->asynchronousOrder());
        $prepended = self::positionOf($lines, 'RoutingSlipPrepender::transform put order.place.endpoint.target.execute in front of the routing slip, which is now order.place.endpoint.target.execute');

        self::assertSame('handler RoutingSlipPrepender::transform returns a message of string', $lines[$prepended - 1]);
        self::assertSame('forward string by the output channel orders, configured on RoutingSlipPrepender::transform; the routing slip order.place.endpoint.target.execute stays on the message for a later step; the reply channel of the request stays on the message', $lines[$prepended + 1]);
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

        self::assertContains('forward string by the output channel order.confirm, configured on class@anonymous::place; the reply channel of the request stays on the message', $lines);
        self::assertContains('reply string to the reply channel of the request, where its sender waits for it', $lines);
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

        self::assertContains('nothing handed over: the handler returned string, and no output channel, routing slip or reply channel of the request takes it', $lines);
        self::assertContains('nothing handed over: the handler returned nothing', $lines);
    }

    public function test_the_entrypoint_sets_the_routing_slip_and_its_router_says_which_header_chose_the_route(): void
    {
        $lines = array_map(trim(...), self::executionOf('tag-partitioned projection backfilled asynchronously in two batches'));
        $entrypoint = self::positionOf($lines, 'entrypoint sends to backfill, setting the routing slip ecotone.projection.partition_batch.executor');

        self::assertSame('route to backfill, named by the header ecotone.messaging.entrypoint of the message', $lines[$entrypoint + 3]);
    }

    /**
     * @return list<string>
     */
    private function asynchronousOrder(): array
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
        });
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
    private function recorded(array $classes, array $services, array $extensionObjects, callable $run): array
    {
        $recorder = new InMemorySpanRecorder();
        $run(EcotoneLite::bootstrapFlowTesting(
            $classes,
            [...$services, ...self::recordingSpansInto($recorder)],
            [...$extensionObjects, self::tracingConfiguration()->withExecutionRecording()],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        ));

        return FlowExecution::of($recorder->recordedSpans())->lines();
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
