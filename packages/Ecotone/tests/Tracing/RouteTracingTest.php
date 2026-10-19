<?php

declare(strict_types=1);

namespace Test\Ecotone\Tracing;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Api\Tracing\Span;
use Ecotone\Api\Tracing\SpanStatus;
use RuntimeException;

/**
 * licence Apache-2.0
 * @internal
 */
final class RouteTracingTest extends TracingTestCase
{
    public function test_a_failing_synchronous_handler_fails_its_route_span(): void
    {
        $recorder = new InMemorySpanRecorder();
        $subscriber = new class () {
            #[EventHandler('merchant.created', endpointId: 'merchant.notifier')]
            public function notify(string $merchantId): void
            {
                throw new RuntimeException('Notification failed');
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$subscriber::class],
            [$subscriber, ...self::recordingSpansInto($recorder)],
            [self::tracingConfiguration()],
        );

        try {
            $ecotone->publishEventWithRouting('merchant.created', '1');
            $this->fail('Expected the handler failure to propagate');
        } catch (RuntimeException) {
        }

        $this->assertSame(SpanStatus::Error, self::spanNamed('route merchant.notifier', $recorder)->status);
    }

    public function test_every_span_names_the_messaging_system_and_handler_spans_name_their_endpoint(): void
    {
        $recorder = new InMemorySpanRecorder();
        $users = new class () {
            #[CommandHandler('user.register', endpointId: 'user.registration')]
            public function register(string $userId): void
            {
            }
        };

        EcotoneLite::bootstrapFlowTesting(
            [$users::class],
            [$users, ...self::recordingSpansInto($recorder)],
            [self::tracingConfiguration()],
        )
            ->sendCommandWithRouting('user.register', '1');

        foreach ($recorder->recordedSpans() as $span) {
            $this->assertSame('ecotone', $span->attributes['messaging.system'], $span->name);
        }
        $this->assertSame('user.registration', self::spanNamed('process class@anonymous::register', $recorder)->attributes['ecotone.endpoint.id']);
    }

    private static function spanNamed(string $name, InMemorySpanRecorder $recorder): Span
    {
        $spans = array_values(array_filter($recorder->recordedSpans(), fn (Span $span): bool => $span->name === $name));
        self::assertNotEmpty($spans, 'Expected a span named ' . $name . ', got: ' . implode(', ', self::spanNamesWithoutFrameworkNoise($recorder->recordedSpans())));

        return $spans[0];
    }
}
