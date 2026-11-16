<?php

declare(strict_types=1);

namespace Test\Ecotone\Tracing;

use Ecotone\Api\Attribute\After;
use Ecotone\Api\Attribute\Around;
use Ecotone\Api\Attribute\Before;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Presend;
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
                '        before class@anonymous::beforePlacing returns nothing',
                '        around class@anonymous::aroundPlacing',
                '          around MessageHandlerLogger::aroundCommandHandler',
                '            around TracingInterceptor::traceHandlerEndpoint',
                '              process class@anonymous::place',
                '                handler class@anonymous::place returns string',
                '        after class@anonymous::afterPlacing returns nothing',
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
                '        presend class@anonymous::beforeSending returns nothing',
                '        around MessageHandlerLogger::aroundCommandHandler',
                '          around TracingInterceptor::traceHandlerEndpoint',
                '            process class@anonymous::place',
                '              handler class@anonymous::place returns nothing',
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
                '        around class@anonymous::skipPlacing',
            ],
            FlowExecution::of($recorder->recordedSpans())->lines(),
        );
    }
}
