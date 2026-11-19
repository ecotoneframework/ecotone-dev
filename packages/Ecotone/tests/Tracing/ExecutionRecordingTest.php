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
use Ecotone\Api\Tracing\TracingConfiguration;
use Ecotone\Api\Tracing\TracingLevel;
use Ecotone\Test\Flow\FlowScenarioCatalogue;
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

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$orders::class, $interceptors::class],
            [$orders, $interceptors],
            [TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework)],
            enabledModules: [],
        )->sendCommandWithRouting('order.place', 'order-1');

        self::assertSame(
            [
                'process 1: send command order.place, class@anonymous::place',
                '  gateway CommandBus::sendWithRouting',
                '    before MessageHeadersPropagatorInterceptor::propagateHeaders',
                '    around TracingInterceptor::traceCommandBus',
                '      span send command order.place',
                '        around MessageHeadersPropagatorInterceptor::storeHeaders',
                '          around DeliveryConfirmationInterceptor::await',
                '            direct channel ecotone.modelling.bus.command_by_name',
                '              route to {generated#1}.target',
                '                ↳ chosen by: name order.place',
                '              direct channel {generated#1}.target',
                '                before class@anonymous::beforePlacing',
                '                around class@anonymous::aroundPlacing',
                '                  around MessageHandlerLogger::aroundCommandHandler',
                '                    around TracingInterceptor::traceHandlerEndpoint',
                '                      span process class@anonymous::place',
                '                        handler class@anonymous::place',
                '                after class@anonymous::afterPlacing',
                '                reply to the reply channel of the request',
                '              no hand-over',
                '                ↳ outcome: nothing returned by the router',
            ],
            $ecotone->popExecutionRecording()->lines,
        );
    }

    public function test_a_presend_interceptor_is_recorded_with_its_role_class_and_method(): void
    {
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

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$orders::class, $interceptors::class],
            [$orders, $interceptors],
            [TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework)],
            enabledModules: [],
        )->sendCommandWithRouting('order.place', 'order-1');

        self::assertSame(
            [
                'process 1: send command order.place, class@anonymous::place',
                '  gateway CommandBus::sendWithRouting',
                '    before MessageHeadersPropagatorInterceptor::propagateHeaders',
                '    around TracingInterceptor::traceCommandBus',
                '      span send command order.place',
                '        around MessageHeadersPropagatorInterceptor::storeHeaders',
                '          around DeliveryConfirmationInterceptor::await',
                '            direct channel ecotone.modelling.bus.command_by_name',
                '              route to {generated#1}.target',
                '                ↳ chosen by: name order.place',
                '              send {generated#1}.target',
                '                before send class@anonymous::beforeSending',
                '                deliver inline to {generated#1}.target',
                '                  direct channel {generated#1}.target',
                '                    around MessageHandlerLogger::aroundCommandHandler',
                '                      around TracingInterceptor::traceHandlerEndpoint',
                '                        span process class@anonymous::place',
                '                          handler class@anonymous::place',
                '                    no hand-over',
                '                      ↳ outcome: nothing returned by class@anonymous::place',
                '              no hand-over',
                '                ↳ outcome: nothing returned by the router',
            ],
            $ecotone->popExecutionRecording()->lines,
        );
    }

    public function test_an_around_interceptor_that_does_not_proceed_has_nothing_recorded_under_it(): void
    {
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

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$orders::class, $interceptors::class],
            [$orders, $interceptors],
            [TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework)],
            enabledModules: [],
        )->sendCommandWithRouting('order.place', 'order-1');

        self::assertSame(
            [
                'process 1: send command order.place',
                '  gateway CommandBus::sendWithRouting',
                '    before MessageHeadersPropagatorInterceptor::propagateHeaders',
                '    around TracingInterceptor::traceCommandBus',
                '      span send command order.place',
                '        around MessageHeadersPropagatorInterceptor::storeHeaders',
                '          around DeliveryConfirmationInterceptor::await',
                '            direct channel ecotone.modelling.bus.command_by_name',
                '              route to {generated#1}.target',
                '                ↳ chosen by: name order.place',
                '              direct channel {generated#1}.target',
                '                around class@anonymous::skipPlacing',
                '                no hand-over',
                '                  ↳ outcome: nothing returned by class@anonymous::place',
                '              no hand-over',
                '                ↳ outcome: nothing returned by the router',
            ],
            $ecotone->popExecutionRecording()->lines,
        );
    }

    public function test_a_command_sent_to_an_asynchronous_channel_is_recorded_as_a_send_through_its_channel_interceptors_and_its_consumption_as_another_process(): void
    {
        $orders = new class () {
            #[Asynchronous('orders')]
            #[CommandHandler('order.place', endpointId: 'order.place.endpoint')]
            public function place(string $order): void
            {
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$orders::class],
            [$orders],
            [TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework), SimpleMessageChannelBuilder::createQueueChannel('orders')],
            enabledModules: [],
        )
            ->sendCommandWithRouting('order.place', 'order-1')
            ->run('orders', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(
            [
                'process 1: send command order.place',
                '  gateway CommandBus::sendWithRouting',
                '    before MessageHeadersPropagatorInterceptor::propagateHeaders',
                '    around TracingInterceptor::traceCommandBus',
                '      span send command order.place',
                '        around MessageHeadersPropagatorInterceptor::storeHeaders',
                '          around DeliveryConfirmationInterceptor::await',
                '            around CollectorSenderInterceptor::send for orders',
                '              direct channel ecotone.modelling.bus.command_by_name',
                '                route to order.place.endpoint.target',
                '                  ↳ chosen by: name order.place',
                '                send order.place.endpoint.target',
                '                  before send MessageHandlerLogger::asynchronous',
                '                  deliver inline to order.place.endpoint.target',
                '                    direct channel order.place.endpoint.target',
                '                      handler RoutingSlipPrepender::transform',
                '                      forward to orders',
                '                        ↳ chosen by: output channel of RoutingSlipPrepender::transform',
                '                        ↳ routing slip: none → order.place.endpoint.target.execute',
                '                        send orders',
                '                          before send OutboundSerializationChannelInterceptor::preSend',
                '                            before send MessageCollectorChannelInterceptor::preSend',
                '                              ↳ collected: held until the handler ends',
                '                no hand-over',
                '                  ↳ outcome: nothing returned by the router',
                '              release collected message to orders',
                '                ↳ collected: released after the handler ended',
                '                ↳ taken by: process 2, a separate consumer run',
                '                before send OutboundSerializationChannelInterceptor::preSend',
                '                  before send MessageCollectorChannelInterceptor::preSend',
                '                    before send InMemoryQueueAcknowledgeInterceptor::preSend',
                '                      before send TracingChannelInterceptor::preSend',
                '                        span send orders',
                '                          put on channel orders',
                '                after send SendRetryChannelInterceptor::afterSendCompletion',
                '                after send TracingChannelInterceptor::afterSendCompletion',
                '                after successful send InMemoryQueueAcknowledgeInterceptor::postSend',
                '  sub-flow process 2: receive orders, class@anonymous::place',
                '    receive orders',
                '      ↳ sent by: process 1, the command order.place',
                '      take from channel orders',
                '      after receive TracingChannelInterceptor::afterReceiveCompletion',
                '      gateway InboundChannelAdapterEntrypoint::executeEntrypoint',
                '        before MessageHeadersPropagatorInterceptor::propagateHeaders',
                '        around TracingInterceptor::traceAsynchronousEndpoint',
                '          span receive orders',
                '            around AcknowledgeConfirmationInterceptor::ack',
                '              around LoggingInterceptor::logException',
                '                around PollingConsumerErrorChannelInterceptor::handle',
                '                  around MessageHeadersPropagatorInterceptor::storeHeaders',
                '                    around AsynchronousEndpointInstantRetryByDefault::retry',
                '                      around DeliveryConfirmationInterceptor::await',
                '                        around CollectorSenderInterceptor::send for orders',
                '                          bridge Bridge::handle',
                '                          forward to order.place.endpoint.target.execute',
                '                            ↳ chosen by: routing slip',
                '                            ↳ routing slip: order.place.endpoint.target.execute → none',
                '                            direct channel order.place.endpoint.target.execute',
                '                              around MessageHandlerLogger::aroundCommandHandler',
                '                                around TracingInterceptor::traceHandlerEndpoint',
                '                                  span process class@anonymous::place',
                '                                    handler class@anonymous::place',
                '                              no hand-over',
                '                                ↳ outcome: nothing returned by class@anonymous::place',
                '              acknowledge',
                '                ↳ outcome: accepted',
            ],
            $ecotone->popExecutionRecording()->lines,
        );
    }

    public function test_a_reply_is_recorded_with_where_it_was_routed(): void
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

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$orders::class],
            [$orders],
            [TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework)],
            enabledModules: [],
        )->sendCommandWithRouting('order.place', 'order-1');

        self::assertSame(
            [
                'process 1: send command order.place, class@anonymous::place',
                '  gateway CommandBus::sendWithRouting',
                '    before MessageHeadersPropagatorInterceptor::propagateHeaders',
                '    around TracingInterceptor::traceCommandBus',
                '      span send command order.place',
                '        around MessageHeadersPropagatorInterceptor::storeHeaders',
                '          around DeliveryConfirmationInterceptor::await',
                '            direct channel ecotone.modelling.bus.command_by_name',
                '              route to {generated#1}.target',
                '                ↳ chosen by: name order.place',
                '              direct channel {generated#1}.target',
                '                around MessageHandlerLogger::aroundCommandHandler',
                '                  around TracingInterceptor::traceHandlerEndpoint',
                '                    span process class@anonymous::place',
                '                      handler class@anonymous::place',
                '                forward to order.confirm',
                '                  ↳ chosen by: output channel of class@anonymous::place',
                '                  publish-subscribe channel order.confirm',
                '                    around MessageHandlerLogger::aroundMessageHandler',
                '                      around TracingInterceptor::traceHandlerEndpoint',
                '                        span process class@anonymous::confirm',
                '                          handler class@anonymous::confirm',
                '                    reply to the reply channel of the request',
                '              no hand-over',
                '                ↳ outcome: nothing returned by the router',
            ],
            $ecotone->popExecutionRecording()->lines,
        );
    }

    public function test_an_asynchronous_backfill_in_two_batches_records_how_each_batch_reports_its_part_and_how_the_parts_are_gathered(): void
    {
        $lines = self::executionOf('tag-partitioned projection backfilled asynchronously in two batches');

        $lines = self::trimmed($lines);
        $expected = [
            'process 2: backfill course_seats, ProjectingConsoleCommands::backfillProjection',
            'handler ProjectingManager::prepareBackfill',
            'sub-flow process 3: receive backfill, PartitionBatchExecutorHandler::executeBatch (1 of 2)',
            'handler PartitionBatchExecutorHandler::executeBatch',
            'part report to ecotone.projection.backfill.completion:course_seats',
            '↳ outcome: part 1 of 2, no reply',
            '↳ collected: held until the handler ends',
            'release collected message to backfill',
            '↳ taken by: process 5, a separate consumer run',
            'sub-flow process 5: receive backfill, GatheringMessageHandler::handle (part 1 of 2)',
            'read part 1 of 2',
            'sub-flow process 4: receive backfill, PartitionBatchExecutorHandler::executeBatch (2 of 2)',
            'part report to ecotone.projection.backfill.completion:course_seats',
            '↳ outcome: part 2 of 2, no reply',
            'release collected message to backfill',
            '↳ taken by: process 6, a separate consumer run',
            'sub-flow process 6: receive backfill, GatheringMessageHandler::handle (part 2 of 2)',
            'read part 2 of 2',
            'release group',
            'handler ProjectionRunCompletion::complete',
        ];

        self::assertSame($expected, self::inOrderOf($lines, $expected));
        self::assertCount(1, array_filter($lines, fn (string $line): bool => $line === 'handler ProjectionRunCompletion::complete'));
    }

    public function test_a_before_interceptor_of_the_entry_runs_as_the_first_step_of_its_process(): void
    {
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

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$orders::class, $interceptors::class],
            [$orders, $interceptors],
            [TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework)],
            enabledModules: [],
        )->sendCommandWithRouting('order.place', 'order-1');

        self::assertSame(
            [
                'process 1: send command order.place, class@anonymous::place',
                '  gateway CommandBus::sendWithRouting',
                '    before MessageHeadersPropagatorInterceptor::propagateHeaders',
                '    before class@anonymous::beforeSendingTheCommand',
                '    around TracingInterceptor::traceCommandBus',
            ],
            array_slice($ecotone->popExecutionRecording()->lines, 0, 5),
        );
    }

    public function test_polls_that_receive_nothing_and_the_housekeeping_of_flow_testing_are_not_recorded(): void
    {
        $orders = new class () {
            #[Asynchronous('orders')]
            #[CommandHandler('order.place', endpointId: 'order.place.endpoint')]
            public function place(string $order): void
            {
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$orders::class],
            [$orders],
            [TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework), SimpleMessageChannelBuilder::createQueueChannel('orders')],
            enabledModules: [],
        )
            ->run('orders', ExecutionPollingMetadata::createWithTestingSetup())
            ->run('orders', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame([], $ecotone->popExecutionRecording()->lines);
    }

    public function test_a_send_that_succeeds_on_retry_is_recorded_as_delivered_with_its_recovery(): void
    {
        $lines = self::trimmed(self::executionOf('event forwarded to a channel whose send succeeds on retry'));

        self::assertStep($lines, 'release collected message to notifications', ['taken by: process 3, a separate consumer run', 'failure: RuntimeException, recovered by SendRetryChannelInterceptor']);
        self::assertSame([], preg_grep('/^↳ failure: throws RuntimeException/', array_slice($lines, (int) array_search('release collected message to notifications', $lines, true), 4)));
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
            [$summaries],
            [TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework), SimpleMessageChannelBuilder::createQueueChannel('summaries')],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $this->expectExceptionMessage('A #[Gathered] handler requires a gathering group opened by its splitter.');

        $ecotone->sendDirectToChannel('summary', 'not a part');
        $ecotone->run('summaries', ExecutionPollingMetadata::createWithTestingSetup());
    }

    public function test_a_process_that_fails_before_its_first_span_is_recorded_with_its_failure(): void
    {
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
            [$orders, $interceptors],
            [TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework)],
            enabledModules: [],
        );

        try {
            $ecotone->sendCommandWithRouting('order.place', 'order-1');
        } catch (RuntimeException) {
        }

        self::assertSame(
            [
                'process 1: gateway CommandBus::sendWithRouting',
                '  gateway CommandBus::sendWithRouting',
                '    ↳ failure: passes RuntimeException out of the process',
                '    before MessageHeadersPropagatorInterceptor::propagateHeaders',
                '    before class@anonymous::refuseTheCommand',
                '      ↳ failure: throws RuntimeException',
            ],
            $ecotone->popExecutionRecording()->lines,
        );
    }

    public function test_entries_that_start_no_span_are_recorded_when_they_run_a_handler(): void
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

            #[ConsoleCommand('orders:noop')]
            public function noop(): void
            {
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$endpoints::class],
            [$endpoints],
            [TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework)],
            enabledModules: [],
        );

        $ecotone->sendDirectToChannel('order.normalize', 'a');
        $ecotone->sendDirectToChannel('order.route', 'b');
        $ecotone->sendDirectToChannel('order.split', 'c,d');
        $ecotone->runConsoleCommand('orders:noop', []);
        $lines = self::trimmed($ecotone->popExecutionRecording()->linesWithEveryHint);

        self::assertCount(4, array_filter($lines, fn (string $line): bool => str_starts_with($line, 'process ')));
        self::assertStep($lines, 'transformer class@anonymous::normalize', ['returns: string']);
        self::assertStep($lines, 'router class@anonymous::route', ['returns: string']);
        self::assertStep($lines, 'splitter class@anonymous::split', ['returns: array']);
        self::assertStep($lines, 'handler class@anonymous::noop', ['returns: nothing']);
    }

    public function test_a_spy_of_flow_testing_on_a_channel_adds_no_send_to_the_recording(): void
    {
        $lines = self::trimmed(self::executionOf('output channel chain of three handlers'));

        self::assertSame([], array_values(array_filter($lines, fn (string $line): bool => str_contains($line, 'SpiecChannelAdapter'))));
        self::assertSame([], array_values(array_filter($lines, fn (string $line): bool => str_starts_with($line, 'send '))));
    }

    public function test_an_outcome_changed_by_flow_testing_is_recorded(): void
    {

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [],
            [],
            [TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework)],
            enabledModules: [],
            testConfiguration: TestConfiguration::createWithDefaults()->withFailOnNotFoundHandler(false),
        )->sendCommandWithRouting('order.unknown', 'order-1');

        self::assertStep(self::trimmed($ecotone->popExecutionRecording()->lines), 'flow testing AllowMissingDestination::invoke', ['outcome: DestinationResolutionException turned into a normal return']);
    }

    public function test_the_reply_of_an_asynchronous_aggregate_action_is_recorded_as_dropped(): void
    {

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [ExecutionRecordedOrder::class],
            [],
            [TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework), SimpleMessageChannelBuilder::createQueueChannel('orders')],
            enabledModules: [],
        )
            ->sendCommandWithRouting('order.place', 'order-1')
            ->sendCommandWithRouting('order.confirm', 'order-1')
            ->run('orders', ExecutionPollingMetadata::createWithTestingSetup());
        $lines = self::trimmed($ecotone->popExecutionRecording()->lines);

        $consumed = array_slice($lines, (int) array_search('sub-flow process 3: receive orders, ExecutionRecordedOrder::confirm', $lines, true));

        self::assertStep($consumed, 'no hand-over', ['outcome: string returned by ExecutionRecordedOrder::confirm, not taken']);
        self::assertNotContains('reply to the reply channel of the request', $consumed);
    }

    public function test_a_message_collected_by_a_handler_that_failed_is_not_read_as_released_when_sent_again(): void
    {
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
            [$orders],
            [TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework), SimpleMessageChannelBuilder::createQueueChannel('orders'), SimpleMessageChannelBuilder::createQueueChannel('notifications')],
            enabledModules: [],
        );

        $ecotone->sendCommandWithRouting('order.place', 'order-1');
        try {
            $ecotone->run('orders', ExecutionPollingMetadata::createWithTestingSetup());
        } catch (RuntimeException) {
        }
        $ecotone->publishEventWithRouting('order.placed', 'order-1', metadata: [MessageHeaders::MESSAGE_ID => '0b3c8e2a-5f8c-4d5e-9a3f-2b1c0d9e8f7a']);
        $lines = self::trimmed($ecotone->popExecutionRecording()->lines);

        self::assertNotContains('release collected message to notifications', $lines);
        self::assertContains('send notifications', $lines);
    }

    public function test_an_aggregate_method_reached_through_the_factory_and_action_router_is_a_handler(): void
    {
        $lines = self::trimmed(self::executionOf('routed factory and action with explicit endpoint ids', withEveryHint: true));

        self::assertStep($lines, 'handler class@anonymous::place', ['returns: class@anonymous']);
        self::assertStep($lines, 'handler class@anonymous::placeAgain', ['returns: nothing']);
        self::assertSame([], array_values(array_filter($lines, fn (string $line): bool => str_starts_with($line, 'step class@anonymous'))));
    }

    public function test_different_generated_names_in_one_recording_stay_different(): void
    {
        $orders = new class () {
            #[CommandHandler('order.place')]
            public function place(string $order): void
            {
            }

            #[CommandHandler('order.cancel')]
            public function cancel(string $order): void
            {
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$orders::class],
            [$orders],
            [TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework)],
            enabledModules: [],
        )
            ->sendCommandWithRouting('order.place', 'order-1')
            ->sendCommandWithRouting('order.cancel', 'order-1');
        $lines = self::trimmed($ecotone->popExecutionRecording()->lines);

        self::assertContains('direct channel {generated#1}.target', $lines);
        self::assertContains('direct channel {generated#2}.target', $lines);
    }

    public function test_a_channel_interceptor_registered_by_its_service_name_is_named_by_its_class(): void
    {
        $lines = self::trimmed(self::executionOf('event forwarded to a channel whose other interceptor fails after the send'));

        self::assertSame([], array_values(array_filter($lines, fn (string $line): bool => str_contains($line, 'failingAfterSend'))));
        self::assertContains('before send AbstractChannelInterceptor@anonymous::preSend', $lines);
    }

    /**
     * @param list<string> $lines
     * @param list<string> $hints
     */
    private static function assertStep(array $lines, string $label, array $hints): void
    {
        $found = [];
        foreach (array_keys($lines, $label, true) as $position) {
            $under = [];
            for ($next = $position + 1; str_starts_with($lines[$next] ?? '', '↳ '); $next++) {
                $under[] = substr($lines[$next], strlen('↳ '));
            }
            $matching = array_values(array_intersect($under, $hints));
            sort($matching);
            $found[] = $label . ': ' . implode('; ', $matching);
        }
        sort($hints);
        self::assertContains($label . ': ' . implode('; ', $hints), $found);
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
    private static function executionOf(string $scenarioName, bool $withEveryHint = false): array
    {
        foreach (FlowScenarioCatalogue::all() as $scenario) {
            if ($scenario->name !== $scenarioName) {
                continue;
            }
            $ecotone = EcotoneLite::bootstrapFlowTesting(
                $scenario->classesToResolve,
                [...$scenario->services],
                [...$scenario->extensionObjects, TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework)],
                enabledModules: [],
                licenceKey: LicenceTesting::VALID_LICENCE,
            );
            foreach (array_keys($scenario->stages) as $stage) {
                self::assertNull($scenario->runStage($stage, $ecotone));
            }

            $recording = $ecotone->popExecutionRecording();

            return $withEveryHint ? $recording->linesWithEveryHint : $recording->lines;
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
