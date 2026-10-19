<?php

declare(strict_types=1);

namespace Test\Ecotone\Tracing;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Api\Tracing\Span;

/**
 * licence Apache-2.0
 * @internal
 */
final class TracingEdgeCasesTest extends TracingTestCase
{
    public function test_a_command_carrying_a_trace_carrier_continues_that_trace_as_a_child(): void
    {
        $recorder = new InMemorySpanRecorder();
        $users = new class () {
            #[CommandHandler('user.register')]
            public function register(string $userId): void
            {
            }
        };

        EcotoneLite::bootstrapFlowTesting(
            [$users::class],
            [$users, ...self::recordingSpansInto($recorder)],
            [self::tracingConfiguration()],
        )
            ->sendCommandWithRouting('user.register', '1', metadata: [MessageHeaders::TRACEPARENT => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01']);

        $commandBus = self::singleSpanNamed('send command', $recorder);
        $this->assertSame('0af7651916cd43dd8448eb211c80319c', $commandBus->traceId);
        $this->assertSame('b7ad6b7169203331', $commandBus->parentSpanId);
        $this->assertSame($commandBus->spanId, self::singleSpanNamed('process class@anonymous::register', $recorder)->parentSpanId);
    }

    public function test_the_trace_carrier_of_the_incoming_command_does_not_leak_into_the_handler_span_parent(): void
    {
        $recorder = new InMemorySpanRecorder();
        $users = new class () {
            #[CommandHandler('user.register')]
            public function register(string $userId): void
            {
            }
        };

        EcotoneLite::bootstrapFlowTesting(
            [$users::class],
            [$users, ...self::recordingSpansInto($recorder)],
            [self::tracingConfiguration()],
        )
            ->sendCommandWithRouting('user.register', '1', metadata: [MessageHeaders::TRACEPARENT => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01']);

        $handler = self::singleSpanNamed('process class@anonymous::register', $recorder);
        $this->assertNotSame('b7ad6b7169203331', $handler->parentSpanId);
    }

    private static function singleSpanNamed(string $name, InMemorySpanRecorder $recorder): Span
    {
        $spans = array_values(array_filter($recorder->recordedSpans(), fn (Span $span): bool => $span->name === $name));
        self::assertCount(1, $spans, 'Expected exactly one span named ' . $name . ', got: ' . implode(', ', self::spanNamesWithoutFrameworkNoise($recorder->recordedSpans())));

        return $spans[0];
    }

}
