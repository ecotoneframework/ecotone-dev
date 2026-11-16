<?php

declare(strict_types=1);

namespace Test\Ecotone\Tracing;

use Ecotone\Api\Attribute\After;
use Ecotone\Api\Attribute\Around;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\Before;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\Presend;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Interceptor\MethodInvocation;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Test\Flow\Snapshot\FlowExecution;

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
                'process 1: send command order.place',
                '  around MessageHeadersPropagatorInterceptor::storeHeaders',
                '    around DeliveryConfirmationInterceptor::await',
                '      gateway GatewayInternalProcessor::process returns a message of string',
                '        direct channel ecotone.modelling.bus.command_by_name',
                '          direct channel {generated}.target',
                '            before class@anonymous::beforePlacing returns nothing',
                '            around class@anonymous::aroundPlacing',
                '              around MessageHandlerLogger::aroundCommandHandler',
                '                around TracingInterceptor::traceHandlerEndpoint',
                '                  span process class@anonymous::place',
                '                    handler class@anonymous::place returns string',
                '            after class@anonymous::afterPlacing returns nothing',
                '            reply string to the reply channel of the request',
                '          no reply',
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
                'process 1: send command order.place',
                '  around MessageHeadersPropagatorInterceptor::storeHeaders',
                '    around DeliveryConfirmationInterceptor::await',
                '      gateway GatewayInternalProcessor::process returns nothing',
                '        direct channel ecotone.modelling.bus.command_by_name',
                '          send {generated}.target',
                '            presend class@anonymous::beforeSending returns nothing',
                '            channel interceptor BeforeSendChannelInterceptor',
                '            direct channel {generated}.target',
                '              around MessageHandlerLogger::aroundCommandHandler',
                '                around TracingInterceptor::traceHandlerEndpoint',
                '                  span process class@anonymous::place',
                '                    handler class@anonymous::place returns nothing',
                '              no reply',
                '          no reply',
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
                '  around MessageHeadersPropagatorInterceptor::storeHeaders',
                '    around DeliveryConfirmationInterceptor::await',
                '      gateway GatewayInternalProcessor::process returns nothing',
                '        direct channel ecotone.modelling.bus.command_by_name',
                '          direct channel {generated}.target',
                '            around class@anonymous::skipPlacing',
                '            no reply',
                '          no reply',
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
                '  around MessageHeadersPropagatorInterceptor::storeHeaders',
                '    around DeliveryConfirmationInterceptor::await',
                '      around CollectorSenderInterceptor::send',
                '        gateway GatewayInternalProcessor::process returns nothing',
                '          direct channel ecotone.modelling.bus.command_by_name',
                '            send order.place.endpoint.target',
                '              presend MessageHandlerLogger::asynchronous returns nothing',
                '              channel interceptor BeforeSendChannelInterceptor',
                '              direct channel order.place.endpoint.target',
                '                handler RoutingSlipPrepender::transform returns a message of string',
                '                reply string to output channel orders',
                '                  send orders',
                '                    channel interceptor OutboundSerializationChannelInterceptor',
                '                    collected, held until the handler ends',
                '            no reply',
                '        send orders',
                '          channel interceptor OutboundSerializationChannelInterceptor',
                '          channel interceptor MessageCollectorChannelInterceptor',
                '          channel interceptor SendRetryChannelInterceptor',
                '          channel interceptor InMemoryQueueAcknowledgeInterceptor',
                '          span send orders',
                '            channel interceptor TracingChannelInterceptor',
                'process 2: receive orders',
                '  around AcknowledgeConfirmationInterceptor::ack',
                '    around LoggingInterceptor::logException',
                '      around PollingConsumerErrorChannelInterceptor::handle',
                '        around MessageHeadersPropagatorInterceptor::storeHeaders',
                '          around AsynchronousEndpointInstantRetryByDefault::retry',
                '            around DeliveryConfirmationInterceptor::await',
                '              around CollectorSenderInterceptor::send',
                '                gateway GatewayInternalProcessor::process returns nothing',
                '                  handler Bridge::handle returns a message of string',
                '                  reply string to routing slip channel order.place.endpoint.target.execute',
                '                    direct channel order.place.endpoint.target.execute',
                '                      around MessageHandlerLogger::aroundCommandHandler',
                '                        around TracingInterceptor::traceHandlerEndpoint',
                '                          span process class@anonymous::place',
                '                            handler class@anonymous::place returns nothing',
                '                      no reply',
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
                'process 1: send command order.place',
                '  around MessageHeadersPropagatorInterceptor::storeHeaders',
                '    around DeliveryConfirmationInterceptor::await',
                '      gateway GatewayInternalProcessor::process returns a message of string',
                '        direct channel ecotone.modelling.bus.command_by_name',
                '          direct channel {generated}.target',
                '            around MessageHandlerLogger::aroundCommandHandler',
                '              around TracingInterceptor::traceHandlerEndpoint',
                '                span process class@anonymous::place',
                '                  handler class@anonymous::place returns string',
                '            reply string to output channel order.confirm',
                '              send order.confirm',
                '                channel interceptor SpiecChannelAdapter',
                '                publish-subscribe channel order.confirm',
                '                  around MessageHandlerLogger::aroundMessageHandler',
                '                    around TracingInterceptor::traceHandlerEndpoint',
                '                      span process class@anonymous::confirm',
                '                        handler class@anonymous::confirm returns string',
                '                  reply string to the reply channel of the request',
                '          no reply',
            ],
            FlowExecution::of($recorder->recordedSpans())->lines(),
        );
    }
}
