<?php

declare(strict_types=1);

namespace Test\Ecotone\Tracing;

use Ecotone\Api\Attribute\After;
use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\Around;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\Before;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\ConsoleCommand;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Gathered;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\Presend;
use Ecotone\Api\Attribute\Router;
use Ecotone\Api\Attribute\Splitter;
use Ecotone\Api\Attribute\Transformer;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Interceptor\MethodInvocation;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Messaging\GatheredParts;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Test\Flow\FlowScenarioCatalogue;
use Ecotone\Test\Flow\Snapshot\FlowExecution;
use Ecotone\Test\LicenceTesting;
use RuntimeException;

/**
 * licence Apache-2.0
 * @internal
 */
final class ExecutionRecordingTest extends TracingTestCase
{
    public function test_before_around_and_after_interceptors_of_a_handler_are_recorded_with_their_role_class_and_method(): void
    {
        $recorder = new InMemorySpanRecorder();
        $orders = new class () {
            #[CommandHandler('order.place')]
            public function place(string $order): string
            {
                return $order;
            }
        };
        $interceptors = new class () {
            #[Before(pointcut: CommandHandler::class)]
            public function beforePlacing(): void
            {
            }

            #[Around(pointcut: CommandHandler::class)]
            public function aroundPlacing(MethodInvocation $invocation): mixed
            {
                return $invocation->proceed();
            }

            #[After(pointcut: CommandHandler::class)]
            public function afterPlacing(): void
            {
            }
        };

        EcotoneLite::bootstrapFlowTesting(
            [$orders::class, $interceptors::class],
            [$orders, $interceptors, ...self::recordingSpansInto($recorder)],
            [self::tracingConfiguration()->withExecutionRecording()],
            enabledModules: [],
        )->sendCommandWithRouting('order.place', 'order-1');

        self::assertSame(
            [
                'process 1: send command order.place, handled by class@anonymous::place',
                '  gateway CommandBus::sendWithRouting returns a message of string',
                '    before MessageHeadersPropagatorInterceptor::propagateHeaders returns array',
                '    around TracingInterceptor::traceCommandBus',
                '      span send command order.place',
                '        around MessageHeadersPropagatorInterceptor::storeHeaders',
                '          around DeliveryConfirmationInterceptor::await',
                '            direct channel ecotone.modelling.bus.command_by_name',
                '              direct channel {generated}.target',
                '                before class@anonymous::beforePlacing returns nothing',
                '                around class@anonymous::aroundPlacing',
                '                  around MessageHandlerLogger::aroundCommandHandler',
                '                    around TracingInterceptor::traceHandlerEndpoint',
                '                      span process class@anonymous::place',
                '                        handler class@anonymous::place returns string',
                '                after class@anonymous::afterPlacing returns nothing',
                '                reply string to the reply channel of the request',
                '              no reply',
            ],
            FlowExecution::of($recorder->recordedSpans())->lines(),
        );
    }

    public function test_a_presend_interceptor_is_recorded_with_its_role_class_and_method(): void
    {
        $recorder = new InMemorySpanRecorder();
        $orders = new class () {
            #[CommandHandler('order.place')]
            public function place(string $order): void
            {
            }
        };
        $interceptors = new class () {
            #[Presend(pointcut: CommandHandler::class)]
            public function beforeSending(): void
            {
            }
        };

        EcotoneLite::bootstrapFlowTesting(
            [$orders::class, $interceptors::class],
            [$orders, $interceptors, ...self::recordingSpansInto($recorder)],
            [self::tracingConfiguration()->withExecutionRecording()],
            enabledModules: [],
        )->sendCommandWithRouting('order.place', 'order-1');

        self::assertSame(
            [
                'process 1: send command order.place, handled by class@anonymous::place',
                '  gateway CommandBus::sendWithRouting returns nothing',
                '    before MessageHeadersPropagatorInterceptor::propagateHeaders returns array',
                '    around TracingInterceptor::traceCommandBus',
                '      span send command order.place',
                '        around MessageHeadersPropagatorInterceptor::storeHeaders',
                '          around DeliveryConfirmationInterceptor::await',
                '            direct channel ecotone.modelling.bus.command_by_name',
                '              send {generated}.target',
                '                presend class@anonymous::beforeSending returns nothing',
                '                direct channel {generated}.target',
                '                  around MessageHandlerLogger::aroundCommandHandler',
                '                    around TracingInterceptor::traceHandlerEndpoint',
                '                      span process class@anonymous::place',
                '                        handler class@anonymous::place returns nothing',
                '                  no reply',
                '              no reply',
            ],
            FlowExecution::of($recorder->recordedSpans())->lines(),
        );
    }

    public function test_an_around_interceptor_that_does_not_proceed_has_nothing_recorded_under_it(): void
    {
        $recorder = new InMemorySpanRecorder();
        $orders = new class () {
            #[CommandHandler('order.place')]
            public function place(string $order): void
            {
            }
        };
        $interceptors = new class () {
            #[Around(pointcut: CommandHandler::class)]
            public function skipPlacing(MethodInvocation $invocation): mixed
            {
                return null;
            }
        };

        EcotoneLite::bootstrapFlowTesting(
            [$orders::class, $interceptors::class],
            [$orders, $interceptors, ...self::recordingSpansInto($recorder)],
            [self::tracingConfiguration()->withExecutionRecording()],
            enabledModules: [],
        )->sendCommandWithRouting('order.place', 'order-1');

        self::assertSame(
            [
                'process 1: send command order.place',
                '  gateway CommandBus::sendWithRouting returns nothing',
                '    before MessageHeadersPropagatorInterceptor::propagateHeaders returns array',
                '    around TracingInterceptor::traceCommandBus',
                '      span send command order.place',
                '        around MessageHeadersPropagatorInterceptor::storeHeaders',
                '          around DeliveryConfirmationInterceptor::await',
                '            direct channel ecotone.modelling.bus.command_by_name',
                '              direct channel {generated}.target',
                '                around class@anonymous::skipPlacing',
                '                no reply',
                '              no reply',
            ],
            FlowExecution::of($recorder->recordedSpans())->lines(),
        );
    }

    public function test_a_command_sent_to_an_asynchronous_channel_is_recorded_as_a_send_through_its_channel_interceptors_and_its_consumption_as_another_process(): void
    {
        $recorder = new InMemorySpanRecorder();
        $orders = new class () {
            #[Asynchronous('orders')]
            #[CommandHandler('order.place', endpointId: 'order.place.endpoint')]
            public function place(string $order): void
            {
            }
        };

        EcotoneLite::bootstrapFlowTesting(
            [$orders::class],
            [$orders, ...self::recordingSpansInto($recorder)],
            [self::tracingConfiguration()->withExecutionRecording(), SimpleMessageChannelBuilder::createQueueChannel('orders')],
            enabledModules: [],
        )
            ->sendCommandWithRouting('order.place', 'order-1')
            ->run('orders', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(
            [
                'process 1: send command order.place',
                '  gateway CommandBus::sendWithRouting returns nothing',
                '    before MessageHeadersPropagatorInterceptor::propagateHeaders returns array',
                '    around TracingInterceptor::traceCommandBus',
                '      span send command order.place',
                '        around MessageHeadersPropagatorInterceptor::storeHeaders',
                '          around DeliveryConfirmationInterceptor::await',
                '            around CollectorSenderInterceptor::send',
                '              direct channel ecotone.modelling.bus.command_by_name',
                '                send order.place.endpoint.target',
                '                  presend MessageHandlerLogger::asynchronous returns nothing',
                '                  direct channel order.place.endpoint.target',
                '                    handler RoutingSlipPrepender::transform returns a message of string',
                '                    reply string to output channel orders',
                '                      send orders',
                '                        channel interceptor OutboundSerializationChannelInterceptor',
                '                        collected, held until the handler ends',
                '                no reply',
                '              release collected message to orders',
                '                channel interceptor OutboundSerializationChannelInterceptor',
                '                channel interceptor MessageCollectorChannelInterceptor',
                '                channel interceptor SendRetryChannelInterceptor',
                '                channel interceptor InMemoryQueueAcknowledgeInterceptor',
                '                span send orders',
                '                  channel interceptor TracingChannelInterceptor',
                'process 2: receive orders, handled by class@anonymous::place',
                '  gateway InboundChannelAdapterEntrypoint::executeEntrypoint returns nothing',
                '    before MessageHeadersPropagatorInterceptor::propagateHeaders returns array',
                '    around TracingInterceptor::traceAsynchronousEndpoint',
                '      span receive orders',
                '        around AcknowledgeConfirmationInterceptor::ack',
                '          around LoggingInterceptor::logException',
                '            around PollingConsumerErrorChannelInterceptor::handle',
                '              around MessageHeadersPropagatorInterceptor::storeHeaders',
                '                around AsynchronousEndpointInstantRetryByDefault::retry',
                '                  around DeliveryConfirmationInterceptor::await',
                '                    around CollectorSenderInterceptor::send',
                '                      handler Bridge::handle returns a message of string',
                '                      reply string to routing slip channel order.place.endpoint.target.execute',
                '                        direct channel order.place.endpoint.target.execute',
                '                          around MessageHandlerLogger::aroundCommandHandler',
                '                            around TracingInterceptor::traceHandlerEndpoint',
                '                              span process class@anonymous::place',
                '                                handler class@anonymous::place returns nothing',
                '                          no reply',
            ],
            FlowExecution::of($recorder->recordedSpans())->lines(),
        );
    }

    public function test_a_reply_is_recorded_with_where_it_was_routed(): void
    {
        $recorder = new InMemorySpanRecorder();
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

        EcotoneLite::bootstrapFlowTesting(
            [$orders::class],
            [$orders, ...self::recordingSpansInto($recorder)],
            [self::tracingConfiguration()->withExecutionRecording()],
            enabledModules: [],
        )->sendCommandWithRouting('order.place', 'order-1');

        self::assertSame(
            [
                'process 1: send command order.place, handled by class@anonymous::place',
                '  gateway CommandBus::sendWithRouting returns a message of string',
                '    before MessageHeadersPropagatorInterceptor::propagateHeaders returns array',
                '    around TracingInterceptor::traceCommandBus',
                '      span send command order.place',
                '        around MessageHeadersPropagatorInterceptor::storeHeaders',
                '          around DeliveryConfirmationInterceptor::await',
                '            direct channel ecotone.modelling.bus.command_by_name',
                '              direct channel {generated}.target',
                '                around MessageHandlerLogger::aroundCommandHandler',
                '                  around TracingInterceptor::traceHandlerEndpoint',
                '                    span process class@anonymous::place',
                '                      handler class@anonymous::place returns string',
                '                reply string to output channel order.confirm',
                '                  publish-subscribe channel order.confirm',
                '                    around MessageHandlerLogger::aroundMessageHandler',
                '                      around TracingInterceptor::traceHandlerEndpoint',
                '                        span process class@anonymous::confirm',
                '                          handler class@anonymous::confirm returns string',
                '                    reply string to the reply channel of the request',
                '              no reply',
            ],
            FlowExecution::of($recorder->recordedSpans())->lines(),
        );
    }

    public function test_an_asynchronous_backfill_in_two_batches_records_how_each_batch_reports_its_part_and_how_the_parts_are_gathered(): void
    {
        $lines = self::executionOf('tag-partitioned projection backfilled asynchronously in two batches');

        $expected = [
            'process 2: backfill course_seats, handled by ProjectingConsoleCommands::backfillProjection',
            '            before MessageHeadersPropagatorInterceptor::propagateHeaders returns array',
            '                  handler ProjectingManager::prepareBackfill returns nothing',
            'process 3: receive backfill, handled by PartitionBatchExecutorHandler::executeBatch (1 of 2)',
            '                              handler PartitionBatchExecutorHandler::executeBatch returns nothing',
            '                          no reply',
            '                          part report of a request without a reply, part 1 of 2, to ecotone.projection.backfill.completion:course_seats',
            '                                  collected, held until the handler ends',
            '                      release collected message to backfill',
            'process 4: receive backfill, handled by PartitionBatchExecutorHandler::executeBatch (2 of 2)',
            '                              handler PartitionBatchExecutorHandler::executeBatch returns nothing',
            '                          no reply',
            '                          part report of a request without a reply, part 2 of 2, to ecotone.projection.backfill.completion:course_seats',
            '                                  collected, held until the handler ends',
            '                      release collected message to backfill',
            'process 5: receive backfill, handled by the gatherer storing part 1 of 2',
            '                          store part 1 of 2',
            'process 6: receive backfill, handled by the gatherer storing part 2 of 2',
            '                          store part 2 of 2',
            '                            release 2 of 2 parts, as all parts are accounted for',
            '                              handler ProjectionRunCompletion::complete returns nothing',
        ];

        self::assertSame($expected, self::inOrderOf($lines, $expected));
        self::assertCount(1, array_filter($lines, fn (string $line): bool => str_contains($line, 'ProjectionRunCompletion::complete')));
    }

    public function test_a_before_interceptor_of_the_entry_runs_as_the_first_step_of_its_process(): void
    {
        $recorder = new InMemorySpanRecorder();
        $orders = new class () {
            #[CommandHandler('order.place')]
            public function place(string $order): void
            {
            }
        };
        $interceptors = new class () {
            #[Before(pointcut: CommandBus::class)]
            public function beforeSendingTheCommand(): void
            {
            }
        };

        EcotoneLite::bootstrapFlowTesting(
            [$orders::class, $interceptors::class],
            [$orders, $interceptors, ...self::recordingSpansInto($recorder)],
            [self::tracingConfiguration()->withExecutionRecording()],
            enabledModules: [],
        )->sendCommandWithRouting('order.place', 'order-1');

        self::assertSame(
            [
                'process 1: send command order.place, handled by class@anonymous::place',
                '  gateway CommandBus::sendWithRouting returns nothing',
                '    before MessageHeadersPropagatorInterceptor::propagateHeaders returns array',
                '    before class@anonymous::beforeSendingTheCommand returns nothing',
                '    around TracingInterceptor::traceCommandBus',
            ],
            array_slice(FlowExecution::of($recorder->recordedSpans())->lines(), 0, 5),
        );
    }

    public function test_polls_that_receive_nothing_and_the_housekeeping_of_flow_testing_are_not_recorded(): void
    {
        $recorder = new InMemorySpanRecorder();
        $orders = new class () {
            #[Asynchronous('orders')]
            #[CommandHandler('order.place', endpointId: 'order.place.endpoint')]
            public function place(string $order): void
            {
            }
        };

        EcotoneLite::bootstrapFlowTesting(
            [$orders::class],
            [$orders, ...self::recordingSpansInto($recorder)],
            [self::tracingConfiguration()->withExecutionRecording(), SimpleMessageChannelBuilder::createQueueChannel('orders')],
            enabledModules: [],
        )
            ->run('orders', ExecutionPollingMetadata::createWithTestingSetup())
            ->run('orders', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame([], FlowExecution::of($recorder->recordedSpans())->lines());
    }

    public function test_a_send_that_succeeds_on_retry_is_recorded_as_delivered_with_its_recovery(): void
    {
        $lines = self::trimmed(self::executionOf('event forwarded to a channel whose send succeeds on retry'));

        self::assertContains('release collected message to notifications', $lines);
        self::assertNotContains('release collected message to notifications throws RuntimeException', $lines);
        self::assertContains('send failed with RuntimeException, recovered by SendRetryChannelInterceptor', $lines);
    }

    public function test_a_message_without_a_gathering_group_fails_with_the_gatherer_own_message_when_recorded(): void
    {
        $summaries = new class () {
            #[Splitter('parts', endpointId: 'parts.split', outputChannelName: 'work', gatherChannelName: 'summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[InternalHandler('work')]
            public function work(int $part): int
            {
                return $part;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('summary', endpointId: 'summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$summaries::class],
            [$summaries, ...self::recordingSpansInto(new InMemorySpanRecorder())],
            [self::tracingConfiguration()->withExecutionRecording(), SimpleMessageChannelBuilder::createQueueChannel('summaries')],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $this->expectExceptionMessage('A #[Gathered] handler requires a gathering group opened by its splitter.');

        $ecotone->sendDirectToChannel('summary', 'not a part');
        $ecotone->run('summaries', ExecutionPollingMetadata::createWithTestingSetup());
    }

    public function test_a_process_that_fails_before_its_first_span_is_recorded_with_its_failure(): void
    {
        $recorder = new InMemorySpanRecorder();
        $orders = new class () {
            #[CommandHandler('order.place')]
            public function place(string $order): void
            {
            }
        };
        $interceptors = new class () {
            #[Before(pointcut: CommandBus::class)]
            public function refuseTheCommand(): void
            {
                throw new RuntimeException('refused');
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$orders::class, $interceptors::class],
            [$orders, $interceptors, ...self::recordingSpansInto($recorder)],
            [self::tracingConfiguration()->withExecutionRecording()],
            enabledModules: [],
        );

        try {
            $ecotone->sendCommandWithRouting('order.place', 'order-1');
        } catch (RuntimeException) {
        }

        self::assertSame(
            [
                'process 1: gateway CommandBus::sendWithRouting',
                '  gateway CommandBus::sendWithRouting throws RuntimeException',
                '    before MessageHeadersPropagatorInterceptor::propagateHeaders returns array',
                '    before class@anonymous::refuseTheCommand throws RuntimeException',
            ],
            FlowExecution::of($recorder->recordedSpans())->lines(),
        );
    }

    public function test_entries_that_start_no_span_are_recorded_when_they_run_a_handler(): void
    {
        $recorder = new InMemorySpanRecorder();
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

            #[ConsoleCommand('orders:noop')]
            public function noop(): void
            {
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$endpoints::class],
            [$endpoints, ...self::recordingSpansInto($recorder)],
            [self::tracingConfiguration()->withExecutionRecording()],
            enabledModules: [],
        );

        $ecotone->sendDirectToChannel('order.normalize', 'a');
        $ecotone->sendDirectToChannel('order.route', 'b');
        $ecotone->sendDirectToChannel('order.split', 'c,d');
        $ecotone->runConsoleCommand('orders:noop', []);
        $lines = self::trimmed(FlowExecution::of($recorder->recordedSpans())->lines());

        self::assertCount(4, array_filter($lines, fn (string $line): bool => str_starts_with($line, 'process ')));
        self::assertContains('handler class@anonymous::normalize returns string', $lines);
        self::assertContains('router class@anonymous::route returns string', $lines);
        self::assertContains('handler class@anonymous::split returns array', $lines);
        self::assertContains('handler class@anonymous::noop returns nothing', $lines);
    }

    public function test_a_spy_of_flow_testing_on_a_channel_adds_no_send_to_the_recording(): void
    {
        $lines = self::trimmed(self::executionOf('output channel chain of three handlers'));

        self::assertSame([], array_values(array_filter($lines, fn (string $line): bool => str_contains($line, 'SpiecChannelAdapter'))));
        self::assertSame([], array_values(array_filter($lines, fn (string $line): bool => str_starts_with($line, 'send '))));
    }

    public function test_an_outcome_changed_by_flow_testing_is_recorded(): void
    {
        $recorder = new InMemorySpanRecorder();

        EcotoneLite::bootstrapFlowTesting(
            [],
            [...self::recordingSpansInto($recorder)],
            [self::tracingConfiguration()->withExecutionRecording()],
            enabledModules: [],
            testConfiguration: TestConfiguration::createWithDefaults()->withFailOnNotFoundHandler(false),
        )->sendCommandWithRouting('order.unknown', 'order-1');

        self::assertContains(
            'flow testing AllowMissingDestination::invoke turned DestinationResolutionException into a normal return',
            self::trimmed(FlowExecution::of($recorder->recordedSpans())->lines()),
        );
    }

    public function test_the_reply_of_an_asynchronous_aggregate_action_is_recorded_as_dropped(): void
    {
        $recorder = new InMemorySpanRecorder();

        EcotoneLite::bootstrapFlowTesting(
            [ExecutionRecordedOrder::class],
            [...self::recordingSpansInto($recorder)],
            [self::tracingConfiguration()->withExecutionRecording(), SimpleMessageChannelBuilder::createQueueChannel('orders')],
            enabledModules: [],
        )
            ->sendCommandWithRouting('order.place', 'order-1')
            ->sendCommandWithRouting('order.confirm', 'order-1')
            ->run('orders', ExecutionPollingMetadata::createWithTestingSetup());
        $lines = self::trimmed(FlowExecution::of($recorder->recordedSpans())->lines());

        self::assertSame('reply string dropped, as no channel waits for it', $lines[array_key_last($lines)]);
    }

    public function test_a_message_collected_by_a_handler_that_failed_is_not_read_as_released_when_sent_again(): void
    {
        $recorder = new InMemorySpanRecorder();
        $orders = new class () {
            #[Asynchronous('orders')]
            #[CommandHandler('order.place', endpointId: 'order.place.endpoint')]
            public function place(string $order, EventBus $eventBus): void
            {
                $eventBus->publishWithRouting('order.placed', $order, metadata: [MessageHeaders::MESSAGE_ID => '0b3c8e2a-5f8c-4d5e-9a3f-2b1c0d9e8f7a']);

                throw new RuntimeException('placing failed');
            }

            #[Asynchronous('notifications')]
            #[EventHandler('order.placed', endpointId: 'order.placed.endpoint')]
            public function notify(string $order): void
            {
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$orders::class],
            [$orders, ...self::recordingSpansInto($recorder)],
            [self::tracingConfiguration()->withExecutionRecording(), SimpleMessageChannelBuilder::createQueueChannel('orders'), SimpleMessageChannelBuilder::createQueueChannel('notifications')],
            enabledModules: [],
        );

        $ecotone->sendCommandWithRouting('order.place', 'order-1');
        try {
            $ecotone->run('orders', ExecutionPollingMetadata::createWithTestingSetup());
        } catch (RuntimeException) {
        }
        $ecotone->publishEventWithRouting('order.placed', 'order-1', metadata: [MessageHeaders::MESSAGE_ID => '0b3c8e2a-5f8c-4d5e-9a3f-2b1c0d9e8f7a']);
        $lines = self::trimmed(FlowExecution::of($recorder->recordedSpans())->lines());

        self::assertNotContains('release collected message to notifications', $lines);
        self::assertContains('send notifications', $lines);
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private static function trimmed(array $lines): array
    {
        return array_map(trim(...), $lines);
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

#[Aggregate]
final class ExecutionRecordedOrder
{
    #[Identifier]
    public string $orderId = '';

    #[CommandHandler('order.place')]
    public static function place(string $orderId): self
    {
        $order = new self();
        $order->orderId = $orderId;

        return $order;
    }

    #[Asynchronous('orders')]
    #[CommandHandler('order.confirm', endpointId: 'order.confirm.endpoint', identifierMapping: ['orderId' => 'payload'])]
    public function confirm(): string
    {
        return 'confirmed';
    }
}
