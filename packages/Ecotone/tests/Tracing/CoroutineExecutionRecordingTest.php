<?php

declare(strict_types=1);

namespace Test\Ecotone\Tracing;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\InstantRetryConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Api\Tracing\Span;
use Ecotone\Api\Tracing\TracingConfiguration;
use Ecotone\Api\Tracing\TracingLevel;
use Ecotone\Test\CoroutineTesting;
use PHPUnit\Framework\Attributes\RequiresMethod;
use RuntimeException;
use Swoole\Coroutine;

/**
 * licence Apache-2.0
 * @internal
 */
#[RequiresMethod(Coroutine::class, 'create')]
final class CoroutineExecutionRecordingTest extends TracingTestCase
{
    public function test_messages_consumed_on_several_coroutines_with_instant_retries_are_recorded_traced_and_handled_as_on_one_coroutine(): void
    {
        $alone = $this->consumedOn(coroutines: 1);
        $atOnce = $this->consumedOn(coroutines: 4);

        self::assertSame($alone['processes'], $atOnce['processes']);
        self::assertSame($alone['traces'], $atOnce['traces']);
        self::assertSame($alone['outcome'], $atOnce['outcome']);
        self::assertSame(4, $atOnce['mostShippedAtOnce']);
    }

    public function test_an_event_published_from_a_coroutine_a_handler_started_is_recorded_as_a_sub_flow_of_the_step_that_started_it(): void
    {
        $orders = new class () {
            #[CommandHandler('order.place')]
            public function place(array $command, EventBus $eventBus): void
            {
                $published = new Coroutine\Channel(1);
                Coroutine::create(static function () use ($eventBus, $published): void {
                    usleep(10_000);
                    $eventBus->publishWithRouting('order.placed', []);
                    $published->push(true);
                });
                $published->pop();
            }

            #[EventHandler('order.placed', endpointId: 'order.notifier')]
            public function notify(): void
            {
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([$orders::class], [$orders], [TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework)], enabledModules: []);

        CoroutineTesting::runAtOnce(
            fn () => $ecotone->sendCommandWithRouting('order.place', []),
            fn () => $ecotone->sendCommandWithRouting('order.place', []),
        );
        $processes = $ecotone->popExecutionRecording()->processes;

        $placing = array_keys(array_filter($processes, fn ($process): bool => str_starts_with($process->title, 'send command order.place')));
        $publishing = array_keys(array_filter($processes, fn ($process): bool => str_starts_with($process->title, 'send event order.placed')));
        self::assertCount(2, $placing);
        self::assertCount(2, $publishing);
        foreach ($publishing as $position) {
            $sentBy = $processes[$position]->sentBy;
            self::assertNotNull($sentBy);
            self::assertContains($sentBy->processNumber - 1, $placing);
            self::assertStringStartsWith('handler class@anonymous::place', $processes[$sentBy->processNumber - 1]->steps[$sentBy->stepNumber - 1]->label);
        }
        self::assertNotSame($processes[$publishing[0]]->sentBy->processNumber, $processes[$publishing[1]]->sentBy->processNumber);
    }

    /**
     * @return array{processes: list<array{string, list<array{int, string, list<string>}>}>, traces: list<list<array{string, ?string, string}>>, outcome: array<string, mixed>, mostShippedAtOnce: int}
     */
    private function consumedOn(int $coroutines): array
    {
        $recorder = new InMemorySpanRecorder();
        $ecotone = $this->shipments($recorder);
        foreach (['parcel-1', 'fragile-2', 'parcel-3', 'fragile-4', 'parcel-5', 'parcel-6', 'fragile-7', 'parcel-8'] as $parcel) {
            $ecotone->sendCommandWithRouting('shipment.ship', ['parcelId' => $parcel]);
        }
        $ecotone->popExecutionRecording();
        $recorder->clear();

        $ecotone->run('shipments', ExecutionPollingMetadata::createWithFinishWhenNoMessages()->withCoroutines($coroutines));

        return [
            'processes' => self::processesOf($ecotone),
            'traces' => self::traceShapesOf($recorder->recordedSpans()),
            'outcome' => [
                'shipped' => $ecotone->sendQueryWithRouting('shipment.shipped'),
                'attempts' => $ecotone->sendQueryWithRouting('shipment.attempts'),
            ],
            'mostShippedAtOnce' => $ecotone->sendQueryWithRouting('shipment.mostShippedAtOnce'),
        ];
    }

    /**
     * @return list<array{string, list<array{int, string, list<string>}>}>
     */
    private static function processesOf(FlowTestSupport $ecotone): array
    {
        $processes = [];
        foreach ($ecotone->popExecutionRecording()->processes as $process) {
            $processes[] = [
                (string) preg_replace('/ \((?:part )?\d+ of \d+\)$/', '', $process->title),
                array_map(fn ($step): array => [$step->depth, $step->label, array_map(fn ($hint): string => $hint->key . ': ' . preg_replace('/process \d+/', 'process #', $hint->value), $step->hints)], $process->steps),
            ];
        }
        usort($processes, fn (array $first, array $second): int => serialize($first) <=> serialize($second));

        return $processes;
    }

    /**
     * @param list<Span> $spans
     * @return list<list<array{string, ?string, string}>>
     */
    private static function traceShapesOf(array $spans): array
    {
        $byId = [];
        foreach ($spans as $span) {
            $byId[$span->spanId] = $span;
        }
        $traces = [];
        foreach ($spans as $span) {
            $traces[$span->traceId][] = [$span->name, isset($byId[(string) $span->parentSpanId]) ? $byId[(string) $span->parentSpanId]->name : null, $span->status->name];
        }
        $shapes = array_map(function (array $trace): array {
            sort($trace);

            return $trace;
        }, array_values($traces));
        usort($shapes, fn (array $first, array $second): int => serialize($first) <=> serialize($second));

        return $shapes;
    }

    private function shipments(InMemorySpanRecorder $recorder): FlowTestSupport
    {
        $shipments = new class () {
            private int $shipped = 0;
            private int $shippedAtOnce = 0;
            private int $mostShippedAtOnce = 0;
            private array $attempts = [];

            #[Asynchronous('shipments')]
            #[CommandHandler('shipment.ship', endpointId: 'shipment.ship.endpoint')]
            public function ship(array $command, EventBus $eventBus): void
            {
                $this->attempts[$command['parcelId']] = ($this->attempts[$command['parcelId']] ?? 0) + 1;
                $this->shippedAtOnce++;
                $this->mostShippedAtOnce = max($this->mostShippedAtOnce, $this->shippedAtOnce);
                usleep(20_000);
                $this->shippedAtOnce--;
                if (str_starts_with($command['parcelId'], 'fragile') && $this->attempts[$command['parcelId']] === 1) {
                    throw new RuntimeException("Parcel {$command['parcelId']} slipped");
                }
                $eventBus->publishWithRouting('shipment.shipped', $command['parcelId']);
                $this->shipped++;
            }

            #[EventHandler('shipment.shipped', endpointId: 'shipment.notifier')]
            public function whenShipped(string $parcelId): void
            {
            }

            #[QueryHandler('shipment.attempts')]
            public function attempts(): array
            {
                ksort($this->attempts);

                return $this->attempts;
            }

            #[QueryHandler('shipment.shipped')]
            public function shipped(): int
            {
                return $this->shipped;
            }

            #[QueryHandler('shipment.mostShippedAtOnce')]
            public function mostShippedAtOnce(): int
            {
                return $this->mostShippedAtOnce;
            }
        };

        return EcotoneLite::bootstrapFlowTesting(
            [$shipments::class],
            [$shipments, ...self::recordingSpansInto($recorder)],
            [
                SimpleMessageChannelBuilder::createQueueChannel('shipments'),
                InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(true, 2),
                self::tracingConfiguration()->withLevel(TracingLevel::Framework),
            ],
            enabledModules: [],
        );
    }
}
