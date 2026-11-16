<?php

declare(strict_types=1);

namespace Test\Ecotone\Tracing;

use Ecotone\Api\Attribute\Apply;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\Fetch;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Attribute\Router;
use Ecotone\Api\Attribute\Splitter;
use Ecotone\Api\Attribute\Transformer;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Modelling\WithAggregateVersioning;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Api\Tracing\Span;
use Ecotone\Test\Flow\FlowScenarioCatalogue;
use Ecotone\Test\Flow\Snapshot\FlowExecution;
use Ecotone\Test\LicenceTesting;
use RuntimeException;
use Throwable;

/**
 * licence Apache-2.0
 * @internal
 */
final class ExecutionRecordingCoverageTest extends TracingTestCase
{
    public function test_the_route_a_command_or_query_bus_selects_is_recorded_with_why(): void
    {
        $orders = new class () {
            #[CommandHandler(endpointId: 'placeOrder')]
            public function place(ExecutionRecordedPlaceOrder $command): void
            {
            }

            #[QueryHandler('order.count', endpointId: 'countOrders')]
            public function count(): int
            {
                return 1;
            }
        };

        $lines = $this->recorded([$orders::class, ExecutionRecordedPlaceOrder::class], [$orders], [], function (FlowTestSupport $ecotone): void {
            $ecotone->sendCommand(new ExecutionRecordedPlaceOrder());
            $ecotone->sendQueryWithRouting('order.count');
        });

        self::assertContains('route by class ExecutionRecordedPlaceOrder to placeOrder.target', $lines);
        self::assertContains('route by name order.count to countOrders.target', $lines);
    }

    public function test_transformers_splitters_routers_bridges_and_orchestrators_are_recorded_in_their_own_role(): void
    {
        $endpoints = new class () {
            #[Transformer('order.normalize')]
            public function normalize(string $order): string
            {
                return strtoupper($order);
            }

            #[Router('order.route')]
            public function route(string $order): string
            {
                return 'order.normalize';
            }

            #[Splitter('order.split', outputChannelName: 'order.normalize')]
            public function split(string $orders): array
            {
                return explode(',', $orders);
            }

            #[Asynchronous('orders')]
            #[CommandHandler('order.place', endpointId: 'placeOrder')]
            public function place(string $order): void
            {
            }
        };

        $lines = $this->recorded([$endpoints::class], [$endpoints], [SimpleMessageChannelBuilder::createQueueChannel('orders')], function (FlowTestSupport $ecotone): void {
            $ecotone->sendDirectToChannel('order.route', 'b');
            $ecotone->sendDirectToChannel('order.split', 'c,d');
            $ecotone->sendCommandWithRouting('order.place', 'e');
            $ecotone->run('orders', ExecutionPollingMetadata::createWithTestingSetup());
        });

        self::assertContains('router class@anonymous::route returns string', $lines);
        self::assertContains('route to order.normalize', $lines);
        self::assertContains('transformer class@anonymous::normalize returns string', $lines);
        self::assertContains('splitter class@anonymous::split returns array', $lines);
        self::assertCount(3, array_keys($lines, 'direct channel order.normalize', true));
        self::assertContains('bridge Bridge::handle returns a message of string', $lines);
        self::assertSame([], array_values(array_filter($lines, fn (string $line): bool => str_starts_with($line, 'handler class@anonymous::normalize') || str_starts_with($line, 'handler class@anonymous::split'))));
        self::assertNotEmpty(array_filter(self::executionOf('orchestrator gateway starting the flow'), fn (string $line): bool => str_starts_with($line, 'orchestrator ')));
    }

    public function test_the_gathering_store_calls_are_recorded_under_the_gatherer(): void
    {
        $lines = self::executionOf('tag-partitioned projection backfilled asynchronously in two batches');

        self::assertContains('open gathering group {generated#1} of 2 parts', $lines);
        self::assertNotEmpty(array_filter($lines, fn (string $line): bool => str_starts_with($line, 'record part 1 of gathering group {generated#1} as arrived, in InMemoryGatheredGroupStore::completePart:')));
        self::assertNotEmpty(array_filter($lines, fn (string $line): bool => str_starts_with($line, 'record part 2 of gathering group {generated#1} as arrived, in InMemoryGatheredGroupStore::completePart:')));
    }

    public function test_a_consumed_message_starts_its_process_at_the_receive_with_what_the_channel_applied(): void
    {
        $lines = $this->recorded([ExecutionRecordedOrders::class], [new ExecutionRecordedOrders()], [SimpleMessageChannelBuilder::createQueueChannel('orders')], function (FlowTestSupport $ecotone): void {
            $ecotone->sendCommandWithRouting('order.place', 'order-1', metadata: [MessageHeaders::PRIORITY => 5]);
            $ecotone->run('orders', ExecutionPollingMetadata::createWithTestingSetup());
        });
        $receive = array_search('process 2: receive orders, handled by ExecutionRecordedOrders::place', $lines, true);

        self::assertIsInt($receive);
        self::assertSame('receive orders: the command order.place, put on the channel by process 1', $lines[$receive + 1]);
        self::assertSame('take the message from channel orders, received with priority 5', $lines[$receive + 2]);
    }

    public function test_the_receive_and_send_phases_of_channel_interceptors_are_recorded(): void
    {
        $lines = $this->recorded([ExecutionRecordedOrders::class], [new ExecutionRecordedOrders()], [SimpleMessageChannelBuilder::createQueueChannel('orders')], function (FlowTestSupport $ecotone): void {
            $ecotone->sendCommandWithRouting('order.place', 'order-1');
            $ecotone->run('orders', ExecutionPollingMetadata::createWithTestingSetup());
        });

        self::assertContains('TracingChannelInterceptor::afterReceiveCompletion, run when the take ended, here with a message and no failure', $lines);
        self::assertContains('SendRetryChannelInterceptor::afterSendCompletion, run when the send ended, here without a failure', $lines);
        self::assertContains('TracingChannelInterceptor::afterSendCompletion, run when the send ended, here without a failure', $lines);
    }

    public function test_the_acknowledgement_of_a_consumed_message_is_recorded_with_the_strategy_that_decided_it(): void
    {
        $lines = $this->recorded([ExecutionRecordedOrders::class], [new ExecutionRecordedOrders()], [SimpleMessageChannelBuilder::createQueueChannel('orders')], function (FlowTestSupport $ecotone): void {
            $ecotone->sendCommandWithRouting('order.place', 'order-1');
            $ecotone->sendCommandWithRouting('order.place', 'failing');
            $ecotone->run('orders', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 2, stopOnError: false));
        });

        self::assertContains('acknowledged: accepted', $lines);
        self::assertContains('acknowledged: resent to the channel, by the final failure strategy resend', $lines);
    }

    public function test_an_aggregate_is_recorded_as_identifier_resolution_load_handler_and_save_with_its_repository_calls(): void
    {
        $lines = self::executionOf('state-stored aggregate recording an event');

        self::assertNotEmpty(array_filter($lines, fn (string $line): bool => str_starts_with($line, 'resolve the aggregate identifier')));
        self::assertNotEmpty(array_filter($lines, fn (string $line): bool => str_starts_with($line, 'save the aggregate')));
        self::assertNotEmpty(array_filter($lines, fn (string $line): bool => str_starts_with($line, 'repository saves ')));
    }

    public function test_the_events_an_aggregate_recorded_are_published_from_its_save(): void
    {
        $lines = FlowExecution::of(self::spansOf('state-stored aggregate recording an event'))->lines();
        $save = array_key_first(array_filter($lines, fn (string $line): bool => str_starts_with(trim($line), 'save the aggregate')));
        $publish = array_key_first(array_filter($lines, fn (string $line, int $position): bool => $position > (int) $save && str_starts_with(trim($line), 'gateway EventBus::publish'), ARRAY_FILTER_USE_BOTH));

        self::assertIsInt($save);
        self::assertIsInt($publish);
        self::assertGreaterThan(strlen($lines[$save]) - strlen(ltrim($lines[$save])), strlen($lines[$publish]) - strlen(ltrim($lines[$publish])));
    }

    public function test_a_saga_load_is_recorded_as_found_or_as_dropping_the_message(): void
    {
        self::assertContains('load the aggregate found it', self::executionOf('saga continued by a second event mapped to it'));
        self::assertContains('load the aggregate dropped the message, as no aggregate was found', self::executionOf('event for an unknown saga is dropped'));
    }

    public function test_an_event_sourced_aggregate_is_recorded_applying_its_events_when_loaded(): void
    {
        $lines = $this->recorded([ExecutionRecordedEventSourcedOrder::class, ExecutionRecordedOrderWasPlaced::class], [], [], function (FlowTestSupport $ecotone): void {
            $ecotone->sendCommandWithRouting('es_order.place', ['orderId' => 'order-1']);
            $ecotone->sendCommandWithRouting('es_order.cancel', ['orderId' => 'order-1']);
        });

        self::assertNotEmpty(array_filter($lines, fn (string $line): bool => str_starts_with($line, 'repository finds ExecutionRecordedEventSourcedOrder')));
        self::assertContains('apply ExecutionRecordedOrderWasPlaced to ExecutionRecordedEventSourcedOrder, by EnterpriseAggregateMethodInvoker', $lines);
    }

    public function test_a_handler_fetching_an_aggregate_runs_the_same_while_recorded(): void
    {
        $orders = new class () {
            public ?string $fetched = null;

            #[CommandHandler('order.inspect')]
            public function inspect(string $orderId, #[Fetch('payload')] ExecutionRecordedEventSourcedOrder $order): void
            {
                $this->fetched = $orderId;
            }
        };

        $lines = $this->recorded([ExecutionRecordedEventSourcedOrder::class, ExecutionRecordedOrderWasPlaced::class, $orders::class], [$orders], [], function (FlowTestSupport $ecotone): void {
            $ecotone->sendCommandWithRouting('es_order.place', ['orderId' => 'order-1']);
            $ecotone->sendCommandWithRouting('order.inspect', 'order-1');
        });

        self::assertSame('order-1', $orders->fetched);
        self::assertContains('repository finds ExecutionRecordedEventSourcedOrder by {"orderId":"order-1"} it at version 1', $lines);
    }

    public function test_a_send_through_the_messaging_entrypoint_is_an_entry_saying_which_headers_it_propagated(): void
    {
        $lines = self::executionOf('tag-partitioned projection backfilled asynchronously in two batches');
        $process = (int) array_search('process 2: backfill course_seats, handled by ProjectingConsoleCommands::backfillProjection', $lines, true);

        self::assertStringStartsWith('entrypoint sends to ecotone.channel.ecotone:projection:backfill, propagating ', $lines[$process + 1]);
        self::assertContains('entrypoint sends to projecting_manager_backfill:course_seats', $lines);
    }

    public function test_a_delayed_retry_and_the_dead_letter_decision_are_recorded(): void
    {
        $lines = self::executionOf('failure is retried with a delay, then sent to the dead letter');

        self::assertNotEmpty(preg_grep('/^send orders as retry 1, delayed \d+ ms; taken from the channel later by a separate consumer run, process 3$/', $lines));
        self::assertContains('forward string by the output channel dead_letter, configured on DelayedRetryErrorHandler::handle; the routing slip order.placing.target.execute stays on the message for a later step', $lines);
    }

    public function test_a_conversion_is_recorded_as_its_own_step_on_the_send_and_on_the_handler_argument(): void
    {
        $orders = new class () {
            #[Asynchronous('orders')]
            #[CommandHandler(endpointId: 'placeOrder')]
            public function place(ExecutionRecordedPlaceOrder $command): void
            {
            }
        };

        $lines = $this->recorded([$orders::class, ExecutionRecordedPlaceOrder::class], [$orders], [SimpleMessageChannelBuilder::createQueueChannel('orders', conversionMediaType: MediaType::APPLICATION_X_PHP_SERIALIZED)], function (FlowTestSupport $ecotone): void {
            $ecotone->sendCommand(new ExecutionRecordedPlaceOrder());
            $ecotone->run('orders', ExecutionPollingMetadata::createWithTestingSetup());
        });

        self::assertContains('convert ExecutionRecordedPlaceOrder (application/x-php) to string (application/x-php-serialized)', $lines);
        self::assertNotEmpty(preg_grep('/^convert argument command$/', $lines));
        self::assertNotEmpty(preg_grep('/^convert string \(application\/x-php-serialized\) to ExecutionRecordedPlaceOrder/', $lines));
    }

    public function test_an_argument_that_cannot_be_converted_is_recorded_as_a_failed_conversion_not_as_the_handler_failing(): void
    {
        $orders = new class () {
            #[CommandHandler('order.place')]
            public function place(ExecutionRecordedPlaceOrder $command): void
            {
            }
        };

        $lines = $this->recorded([$orders::class, ExecutionRecordedPlaceOrder::class], [$orders], [], function (FlowTestSupport $ecotone): void {
            try {
                $ecotone->sendCommandWithRouting('order.place', 'not an order');
            } catch (Throwable) {
            }
        });

        self::assertNotEmpty(preg_grep('/^convert argument command throws /', $lines));
        self::assertEmpty(preg_grep('/^handler class@anonymous::place/', $lines));
    }

    public function test_a_projection_run_records_its_transaction_stream_loads_flush_partition_saves_and_the_dispatch_of_its_batches(): void
    {
        $lines = self::executionOf('tag-partitioned projection backfilled asynchronously in two batches');
        $dispatching = array_slice($lines, (int) array_search('process 2: backfill course_seats, handled by ProjectingConsoleCommands::backfillProjection', $lines, true), 40);

        self::assertContains('dispatch 2 partition batches of backfill to backfill, by GatheredPartitionBatchDispatcher', $dispatching);
        self::assertNotEmpty(preg_grep('/^open gathering group \{generated#\d+\} of 2 parts$/', $dispatching));
        self::assertContains('begin the projection transaction', $lines);
        self::assertContains('commit the projection transaction', $lines);
        self::assertNotEmpty(preg_grep('/^load events of course_seats /', $lines));
        self::assertNotEmpty(preg_grep('/^save partition .* of course_seats$/', $lines));
        self::assertContains('flush the projection', $lines);
    }

    public function test_a_step_run_by_a_service_the_licence_chose_names_that_service(): void
    {
        $splitter = new class () {
            #[Splitter('order.split', outputChannelName: 'order.item')]
            public function split(string $orders): array
            {
                return explode(',', $orders);
            }

            #[InternalHandler('order.item')]
            public function item(string $item): void
            {
            }
        };
        $run = fn (FlowTestSupport $ecotone) => $ecotone->sendDirectToChannel('order.split', 'a,b');

        self::assertContains('MethodInvokerProcessor, chosen by the licence', $this->recorded([$splitter::class], [$splitter], [], $run, licenceKey: null));
        self::assertContains('UngatheredSplitterProcessor, chosen by the licence', $this->recorded([$splitter::class], [$splitter], [], $run));
    }

    public function test_a_consumer_that_stops_on_a_failure_is_recorded_releasing_the_message(): void
    {
        $lines = $this->recorded([ExecutionRecordedOrders::class], [new ExecutionRecordedOrders()], [SimpleMessageChannelBuilder::createQueueChannel('orders')], function (FlowTestSupport $ecotone): void {
            $ecotone->sendCommandWithRouting('order.place', 'failing');
            try {
                $ecotone->run('orders', ExecutionPollingMetadata::createWithTestingSetup());
            } catch (RuntimeException) {
            }
        });

        self::assertContains('acknowledged: released back to the channel, and the consumer stops (final failure strategy resend)', $lines);
    }

    /**
     * @param list<class-string> $classes
     * @param list<object> $services
     * @param list<object> $extensionObjects
     * @param callable(FlowTestSupport): void $run
     * @return list<string>
     */
    private function recorded(array $classes, array $services, array $extensionObjects, callable $run, ?string $licenceKey = LicenceTesting::VALID_LICENCE): array
    {
        $recorder = new InMemorySpanRecorder();
        $run(EcotoneLite::bootstrapFlowTesting(
            $classes,
            [...$services, ...self::recordingSpansInto($recorder)],
            [...$extensionObjects, self::tracingConfiguration()->withExecutionRecording()],
            enabledModules: [],
            licenceKey: $licenceKey,
        ));

        return array_map(trim(...), FlowExecution::of($recorder->recordedSpans())->lines());
    }

    /**
     * @return list<string>
     */
    private static function executionOf(string $scenarioName): array
    {
        return array_map(trim(...), FlowExecution::of(self::spansOf($scenarioName))->lines());
    }

    /**
     * @return list<Span>
     */
    private static function spansOf(string $scenarioName): array
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

            return $recorder->recordedSpans();
        }
        self::fail("No scenario named {$scenarioName} in the flow scenario catalogue");
    }
}

final class ExecutionRecordedPlaceOrder
{
}

final class ExecutionRecordedOrders
{
    #[Asynchronous('orders')]
    #[CommandHandler('order.place', endpointId: 'placeOrder')]
    public function place(string $order): void
    {
        if ($order === 'failing') {
            throw new RuntimeException('placing failed');
        }
    }
}

#[EventSourcingAggregate]
final class ExecutionRecordedEventSourcedOrder
{
    use WithAggregateVersioning;

    #[Identifier]
    private string $orderId;

    #[CommandHandler('es_order.place')]
    public static function place(array $command): array
    {
        return [new ExecutionRecordedOrderWasPlaced($command['orderId'])];
    }

    #[CommandHandler('es_order.cancel')]
    public function cancel(): array
    {
        return [];
    }

    #[Apply]
    public function applyPlaced(ExecutionRecordedOrderWasPlaced $event): void
    {
        $this->orderId = $event->orderId;
    }
}

final class ExecutionRecordedOrderWasPlaced
{
    public function __construct(public readonly string $orderId)
    {
    }
}
