<?php

declare(strict_types=1);

namespace Test\Ecotone\Tracing;

use DateTimeImmutable;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Api\Tracing\Span;
use Ecotone\Api\Tracing\SpanAttribute;
use Ecotone\Api\Tracing\TracingConfiguration;
use Ecotone\Api\Tracing\TracingLevel;
use Fiber;
use RuntimeException;

/**
 * licence Apache-2.0
 * @internal
 */
final class ExecutionRecordingInterleavingTest extends TracingTestCase
{
    public function test_two_commands_handled_interleaved_are_each_recorded_as_their_own_process_as_when_handled_one_after_the_other(): void
    {
        $sequential = $this->application();
        $sequential->sendCommandWithRouting('order.place', ['orderId' => 'first', 'suspend' => false]);
        $sequential->sendCommandWithRouting('order.place', ['orderId' => 'second', 'suspend' => false]);

        $interleaved = $this->application();
        $suspended = new Fiber(fn () => $interleaved->sendCommandWithRouting('order.place', ['orderId' => 'first', 'suspend' => true]));
        $suspended->start();
        $interleaved->sendCommandWithRouting('order.place', ['orderId' => 'second', 'suspend' => false]);
        $suspended->resume();

        self::assertSame(self::processesOf($sequential), self::processesOf($interleaved));
    }

    public function test_two_consumed_messages_handled_interleaved_are_each_recorded_as_their_own_process_as_when_consumed_one_after_the_other(): void
    {
        $sequential = $this->application();
        $sequential->sendCommandWithRouting('order.ship', ['orderId' => 'first', 'suspend' => false]);
        $sequential->sendCommandWithRouting('order.ship', ['orderId' => 'second', 'suspend' => false]);
        $sequential->run('shipments', ExecutionPollingMetadata::createWithFinishWhenNoMessages()->withHandledMessageLimit(2));

        $interleaved = $this->application();
        $interleaved->sendCommandWithRouting('order.ship', ['orderId' => 'first', 'suspend' => true]);
        $interleaved->sendCommandWithRouting('order.ship', ['orderId' => 'second', 'suspend' => false]);
        $suspended = new Fiber(fn () => $interleaved->run('shipments', ExecutionPollingMetadata::createWithFinishWhenNoMessages()->withHandledMessageLimit(1)));
        $suspended->start();
        $interleaved->run('shipments', ExecutionPollingMetadata::createWithFinishWhenNoMessages()->withHandledMessageLimit(1));
        $suspended->resume();

        self::assertSame(self::processesOf($sequential), self::processesOf($interleaved));
    }

    public function test_a_command_failing_while_another_is_suspended_leaves_the_suspended_one_recorded_whole(): void
    {
        $sequential = $this->application();
        $sequential->sendCommandWithRouting('order.place', ['orderId' => 'first', 'suspend' => false]);
        try {
            $sequential->sendCommandWithRouting('order.refuse', 'second');
        } catch (RuntimeException) {
        }

        $interleaved = $this->application();
        $suspended = new Fiber(fn () => $interleaved->sendCommandWithRouting('order.place', ['orderId' => 'first', 'suspend' => true]));
        $suspended->start();
        try {
            $interleaved->sendCommandWithRouting('order.refuse', 'second');
        } catch (RuntimeException) {
        }
        $suspended->resume();

        self::assertSame(self::processesOf($sequential), self::processesOf($interleaved));
    }

    public function test_every_step_span_of_two_interleaved_commands_is_in_the_trace_of_its_own_command_under_a_span_of_that_command(): void
    {
        $recorder = new InMemorySpanRecorder();
        $interleaved = $this->application($recorder);
        $suspended = new Fiber(fn () => $interleaved->sendCommandWithRouting('order.place', ['orderId' => 'first', 'suspend' => true]));
        $suspended->start();
        $interleaved->sendCommandWithRouting('order.place', ['orderId' => 'second', 'suspend' => false]);
        $suspended->resume();
        $spans = $recorder->recordedSpans();
        $byId = [];
        foreach ($spans as $span) {
            $byId[$span->spanId] = $span;
        }
        $traces = array_values(array_unique(array_map(fn (Span $span): string => $span->traceId, $spans)));

        self::assertCount(2, $traces);
        foreach ($spans as $span) {
            if ($span->parentSpanId !== null && isset($byId[$span->parentSpanId])) {
                self::assertSame($byId[$span->parentSpanId]->traceId, $span->traceId);
            }
        }
        self::assertCount(0, array_filter($spans, fn (Span $span): bool => ($span->attributes[SpanAttribute::EXECUTION_STEP] ?? false) && $span->parentSpanId !== null && ! isset($byId[$span->parentSpanId])));
    }

    public function test_interleaved_commands_release_their_recording_state_when_each_ends(): void
    {
        $interleaved = $this->application();
        $memoryAfter = [];
        foreach ([1, 2, 3, 4] as $round) {
            for ($pair = 0; $pair < 100; $pair++) {
                $suspended = new Fiber(fn () => $interleaved->sendCommandWithRouting('order.place', ['orderId' => 'first', 'suspend' => true]));
                $suspended->start();
                $interleaved->sendCommandWithRouting('order.place', ['orderId' => 'second', 'suspend' => false]);
                $suspended->resume();
            }
            $interleaved->popExecutionRecording();
            $interleaved->discardRecordedMessages();
            unset($suspended);
            gc_collect_cycles();
            $memoryAfter[$round] = memory_get_usage();
        }

        self::assertLessThan(64 * 1024, $memoryAfter[4] - $memoryAfter[2]);
    }

    /**
     * @return list<array{string, list<array{int, string}>}>
     */
    private static function processesOf(FlowTestSupport $ecotone): array
    {
        $processes = [];
        foreach ($ecotone->popExecutionRecording()->processes as $process) {
            $processes[] = [$process->title, array_map(fn ($step): array => [$step->depth, $step->label], $process->steps)];
        }

        return $processes;
    }

    private function application(?InMemorySpanRecorder $recorder = null): FlowTestSupport
    {
        $orders = new class () {
            #[CommandHandler('order.place')]
            public function place(array $command, EventBus $eventBus): void
            {
                if ($command['suspend']) {
                    Fiber::suspend();
                }
                $eventBus->publishWithRouting('order.placed', $command['orderId']);
            }

            #[EventHandler('order.placed', endpointId: 'order.notifier')]
            public function notify(string $orderId): void
            {
            }

            #[CommandHandler('order.refuse')]
            public function refuse(string $orderId): void
            {
                throw new RuntimeException('the order is refused');
            }

            #[Asynchronous('shipments')]
            #[CommandHandler('order.ship', endpointId: 'order.shipping')]
            public function ship(array $command, EventBus $eventBus): void
            {
                if ($command['suspend']) {
                    Fiber::suspend();
                }
                $eventBus->publishWithRouting('order.shipped', $command['orderId']);
            }

            #[EventHandler('order.shipped', endpointId: 'shipment.notifier')]
            public function whenShipped(string $orderId): void
            {
            }
        };

        return EcotoneLite::bootstrapFlowTesting(
            [$orders::class],
            [$orders, ...($recorder === null ? [] : self::recordingSpansInto($recorder))],
            [SimpleMessageChannelBuilder::createQueueChannel('shipments'), ($recorder === null ? TracingConfiguration::createWithDefaults() : self::tracingConfiguration())->withLevel(TracingLevel::Framework)],
            enabledModules: [],
            initialTime: new DateTimeImmutable('2026-11-22 10:00:00'),
        );
    }
}
