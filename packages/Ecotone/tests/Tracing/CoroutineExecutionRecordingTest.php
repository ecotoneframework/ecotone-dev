<?php

declare(strict_types=1);

namespace Test\Ecotone\Tracing;

use DateTimeImmutable;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\InstantRetryConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Api\Tracing\RecordedStep;
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

        self::assertCount(8, $atOnce['processes']);
        self::assertCount(8, $atOnce['traces']);
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
        $ecotone = EcotoneLite::bootstrapFlowTesting([], [$orders], [TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework)], enabledModules: [], initialTime: new DateTimeImmutable('2026-11-22 10:00:00'));

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
        $first = $processes[$publishing[0]];
        $startingStep = $processes[$first->sentBy->processNumber - 1]->steps[$first->sentBy->stepNumber - 1];
        self::assertContains('started in: a coroutine started by process ' . $first->sentBy->processNumber, array_map(fn ($hint): string => $hint->key . ': ' . $hint->value, $first->steps[0]->hints));
        self::assertContains('starts: process ' . ($publishing[0] + 1) . ', in a coroutine', array_map(fn ($hint): string => $hint->key . ': ' . $hint->value, $startingStep->hints));
    }

    public function test_a_coroutine_that_outlives_its_handler_is_a_sub_flow_of_the_step_its_handler_ran_in_not_of_a_later_one(): void
    {
        $orders = new class () {
            private ?Coroutine\Channel $nextStarted = null;
            private ?Coroutine\Channel $published = null;

            #[CommandHandler('outlivesInto', outputChannelName: 'next')]
            public function outlivesInto(array $command, EventBus $eventBus): array
            {
                $this->nextStarted = new Coroutine\Channel(1);
                $this->published = new Coroutine\Channel(1);
                $nextStarted = $this->nextStarted;
                $published = $this->published;
                Coroutine::create(static function () use ($eventBus, $nextStarted, $published): void {
                    $nextStarted->pop();
                    $eventBus->publishWithRouting('order.placed', []);
                    $published->push(true);
                });

                return $command;
            }

            #[InternalHandler(inputChannelName: 'next')]
            public function next(array $command): void
            {
                $this->nextStarted?->push(true);
                $this->published?->pop();
            }

            #[EventHandler('order.placed', endpointId: 'order.notifier')]
            public function notify(): void
            {
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([], [$orders], [TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework)], enabledModules: [], initialTime: new DateTimeImmutable('2026-11-22 10:00:00'));

        CoroutineTesting::runAtOnce(fn () => $ecotone->sendCommandWithRouting('outlivesInto', []));
        $processes = $ecotone->popExecutionRecording()->processes;

        [$published] = array_values(array_filter($processes, fn ($process): bool => str_starts_with($process->title, 'send event order.placed')));
        self::assertNotNull($published->sentBy);
        $steps = $processes[$published->sentBy->processNumber - 1]->steps;
        $stepOf = fn (string $label): int => (int) array_key_first(array_filter($steps, fn (RecordedStep $step): bool => $step->label === $label));
        $stillOpenAncestors = array_intersect(self::ancestorsOf(steps: $steps, step: $stepOf('handler class@anonymous::outlivesInto')), self::ancestorsOf(steps: $steps, step: $stepOf('handler class@anonymous::next')));
        self::assertSame(max($stillOpenAncestors), $published->sentBy->stepNumber - 1);
    }

    /**
     * @param list<RecordedStep> $steps
     * @return list<int>
     */
    private static function ancestorsOf(array $steps, int $step): array
    {
        $ancestors = [];
        $depth = $steps[$step]->depth;
        for ($earlier = $step - 1; $earlier >= 0; $earlier--) {
            if ($steps[$earlier]->depth < $depth) {
                $ancestors[] = $earlier;
                $depth = $steps[$earlier]->depth;
            }
        }

        return $ancestors;
    }

    /**
     * @return array{processes: list<array{string, list<array{int, string, list<string>}>}>, traces: list<list<array{string, ?string, string}>>, outcome: array<string, mixed>, mostShippedAtOnce: int}
     */
    private function consumedOn(int $coroutines): array
    {
        $recorder = new InMemorySpanRecorder();
        $ecotone = $this->shipments(recorder: $recorder, atOnce: $coroutines);
        foreach (['parcel-1', 'fragile-2', 'parcel-3', 'fragile-4', 'parcel-5', 'parcel-6', 'fragile-7', 'parcel-8'] as $parcel) {
            $ecotone->sendCommandWithRouting('shipment.ship', ['parcelId' => $parcel]);
        }
        $ecotone->popExecutionRecording();
        $recorder->clear();

        $ecotone->run('shipments', ExecutionPollingMetadata::createWithFinishWhenNoMessages()->withCoroutines($coroutines));

        return [
            'processes' => ExecutionRecordingShapes::processesInAnyOrderOf($ecotone->popExecutionRecording()),
            'traces' => ExecutionRecordingShapes::traceShapesInAnyOrderOf($recorder->recordedSpans()),
            'outcome' => [
                'shipped' => $ecotone->sendQueryWithRouting('shipment.shipped'),
                'attempts' => $ecotone->sendQueryWithRouting('shipment.attempts'),
            ],
            'mostShippedAtOnce' => $ecotone->sendQueryWithRouting('shipment.mostShippedAtOnce'),
        ];
    }

    private function shipments(InMemorySpanRecorder $recorder, int $atOnce): FlowTestSupport
    {
        $shipments = new class ($atOnce) {
            private int $shipped = 0;
            private int $shippedAtOnce = 0;
            private int $mostShippedAtOnce = 0;
            private array $attempts = [];
            private int $arrivedAtOnce = 0;
            private ?Coroutine\Channel $allArrived = null;

            public function __construct(private int $atOnce)
            {
            }

            #[Asynchronous('shipments')]
            #[CommandHandler('shipment.ship', endpointId: 'shipment.ship.endpoint')]
            public function ship(array $command, EventBus $eventBus): void
            {
                $this->attempts[$command['parcelId']] = ($this->attempts[$command['parcelId']] ?? 0) + 1;
                $this->shippedAtOnce++;
                $this->mostShippedAtOnce = max($this->mostShippedAtOnce, $this->shippedAtOnce);
                $this->waitUntilShippingAtOnce();
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

            private function waitUntilShippingAtOnce(): void
            {
                if ($this->arrivedAtOnce >= $this->atOnce) {
                    return;
                }
                $this->allArrived ??= new Coroutine\Channel($this->atOnce);
                if (++$this->arrivedAtOnce === $this->atOnce) {
                    for ($waiting = 1; $waiting < $this->atOnce; $waiting++) {
                        $this->allArrived->push(true);
                    }

                    return;
                }
                if ($this->allArrived->pop(5.0) === false) {
                    throw new RuntimeException("Only {$this->arrivedAtOnce} of {$this->atOnce} messages were being handled at once after 5 seconds, so the consumer did not run its coroutines at once");
                }
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
            [],
            [$shipments, ...self::recordingSpansInto($recorder)],
            [
                SimpleMessageChannelBuilder::createQueueChannel('shipments'),
                InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(true, 2),
                self::tracingConfiguration()->withLevel(TracingLevel::Framework),
            ],
            enabledModules: [],
            initialTime: new DateTimeImmutable('2026-11-22 10:00:00'),
        );
    }
}
