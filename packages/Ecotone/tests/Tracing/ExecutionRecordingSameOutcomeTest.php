<?php

declare(strict_types=1);

namespace Test\Ecotone\Tracing;

use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\Collected;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\Presend;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\CollectedMessages;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Api\Tracing\Span;
use Ecotone\Api\Tracing\SpanAttribute;
use Ecotone\Api\Tracing\TracingConfiguration;
use Ecotone\Api\Tracing\TracingLevel;
use Ecotone\Test\LicenceTesting;
use RuntimeException;
use Throwable;

/**
 * licence Apache-2.0
 * @internal
 */
final class ExecutionRecordingSameOutcomeTest extends TracingTestCase
{
    public function test_a_routing_key_that_is_not_valid_utf8_ends_the_same_at_both_tracing_levels(): void
    {
        $listeners = new class () {
            public array $heard = [];

            #[EventHandler('order.*')]
            public function hear(string $event): void
            {
                $this->heard[] = $event;
            }
        };
        $run = fn (FlowTestSupport $ecotone) => $ecotone->publishEventWithRouting("order.\xC3\x28", 'x');

        $this->assertSameOutcomeWithRecordingOnAndOff([$listeners::class], fn (): array => [clone $listeners], $run, fn (object $listeners): array => $listeners->heard);
    }

    public function test_a_collector_keyed_by_a_binary_value_ends_the_same_at_both_tracing_levels(): void
    {
        $collector = new class () {
            public array $batches = [];

            #[Collected(maxSize: 2, groupBy: "headers['tenant']")]
            #[Asynchronous('batches')]
            #[InternalHandler('collect', endpointId: 'collect.handler')]
            public function collect(CollectedMessages $batch): void
            {
                $this->batches[] = count($batch->messages);
            }
        };
        $run = function (FlowTestSupport $ecotone): void {
            $ecotone->sendDirectToChannel('collect', 'a', ['tenant' => "\xFF\xFE"]);
            $ecotone->sendDirectToChannel('collect', 'b', ['tenant' => "\xFF\xFE"]);
            $ecotone->run('batches', ExecutionPollingMetadata::createWithTestingSetup());
        };

        $this->assertSameOutcomeWithRecordingOnAndOff([$collector::class], fn (): array => [clone $collector], $run, fn (object $collector): array => $collector->batches, [SimpleMessageChannelBuilder::createQueueChannel('batches')]);
    }

    public function test_an_aggregate_with_a_binary_identifier_ends_the_same_at_both_tracing_levels(): void
    {
        $run = function (FlowTestSupport $ecotone): void {
            $ecotone->sendCommandWithRouting('order.place', "\xFF\xFE");
            $ecotone->sendCommandWithRouting('order.confirm', "\xFF\xFE");
            $ecotone->run('orders', ExecutionPollingMetadata::createWithTestingSetup());
        };

        $this->assertSameOutcomeWithRecordingOnAndOff([ExecutionRecordedBinaryOrder::class], fn (): array => [], $run, fn (): array => [], [SimpleMessageChannelBuilder::createQueueChannel('orders')]);
    }

    public function test_recording_the_execution_leaves_the_spans_of_the_trace_as_they_are_without_it(): void
    {
        $orders = new class () {
            #[CommandHandler('order.place')]
            public function place(string $order, EventBus $eventBus): void
            {
                $eventBus->publishWithRouting('order.placed', $order);
            }

            #[Asynchronous('notifications')]
            #[EventHandler('order.placed', endpointId: 'notify')]
            public function notify(string $order): void
            {
            }
        };
        $traces = [];
        foreach ([false, true] as $recording) {
            $recorder = new InMemorySpanRecorder();
            $tracing = self::tracingConfiguration();
            $ecotone = EcotoneLite::bootstrapFlowTesting([$orders::class], [$orders, ...self::recordingSpansInto($recorder)], [SimpleMessageChannelBuilder::createQueueChannel('notifications'), $recording ? $tracing->withLevel(TracingLevel::Framework) : $tracing], enabledModules: []);
            $ecotone->sendCommandWithRouting('order.place', 'order-1');
            $ecotone->run('notifications', ExecutionPollingMetadata::createWithTestingSetup());
            $traced = array_values(array_filter($recorder->recordedSpans(), fn (Span $span): bool => ! isset($span->attributes[SpanAttribute::EXECUTION_STEP])));
            $nameOf = array_column(array_map(fn (Span $span): array => [$span->spanId, $span->name], $traced), 1, 0);
            $traces[] = array_map(fn (Span $span): array => [$span->name, $span->kind, $span->status, $nameOf[$span->parentSpanId ?? ''] ?? null], $traced);
        }

        self::assertNotEmpty($traces[0]);
        self::assertSame($traces[0], $traces[1]);
    }

    public function test_two_tracing_configurations_are_refused_at_bootstrap_instead_of_one_of_their_levels_being_picked(): void
    {
        $this->expectExceptionMessage('TracingConfiguration was registered 2 times, but an application traces at one level with one configuration, so it is not clear which level to use. Keep a single #[ServiceContext] returning TracingConfiguration, with the level set by TracingConfiguration::withLevel(), and remove the others.');

        EcotoneLite::bootstrapFlowTesting([], self::recordingSpansInto(new InMemorySpanRecorder()), [self::tracingConfiguration(), TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework)], enabledModules: []);
    }

    public function test_a_process_that_failed_leaves_nothing_in_the_recording_of_the_next_one(): void
    {
        $orders = new class () {
            #[CommandHandler('order.place')]
            public function place(string $order, EventBus $eventBus): void
            {
                $eventBus->publishWithRouting('order.placed', $order);
                if ($order === 'failing') {
                    throw new RuntimeException('the order cannot be placed');
                }
            }

            #[Asynchronous('notifications')]
            #[EventHandler('order.placed', endpointId: 'notify')]
            public function notify(string $order): void
            {
            }
        };
        $recordingOf = function (array $orderIds) use ($orders): array {
            $ecotone = EcotoneLite::bootstrapFlowTesting([$orders::class], [$orders], [SimpleMessageChannelBuilder::createQueueChannel('notifications'), TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework)], enabledModules: []);
            foreach ($orderIds as $orderId) {
                try {
                    $ecotone->sendCommandWithRouting('order.place', $orderId);
                } catch (RuntimeException) {
                }
            }

            return $ecotone->popExecutionRecording()->linesWithEveryHint;
        };

        $alone = $recordingOf(['order-1']);
        $afterAFailure = $recordingOf(['failing', 'order-1']);

        self::assertSame(
            str_replace('process 1: send command order.place, class@anonymous::place', 'process 2: send command order.place, class@anonymous::place (2 of 2)', $alone),
            array_slice($afterAFailure, (int) array_search('process 2: send command order.place, class@anonymous::place (2 of 2)', $afterAFailure, true)),
        );
    }

    public function test_a_send_that_failed_leaves_nothing_in_the_recording_of_the_next_process(): void
    {
        $orders = new class () {
            #[CommandHandler('order.place', endpointId: 'placeOrder')]
            public function place(string $order): void
            {
            }

            #[Presend]
            public function refuse(string $order): void
            {
                if ($order === 'failing') {
                    throw new RuntimeException('the order is refused before it is sent');
                }
            }
        };
        $recordingOf = function (array $orderIds) use ($orders): array {
            $ecotone = EcotoneLite::bootstrapFlowTesting([$orders::class], [$orders], [TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework)], enabledModules: []);
            foreach ($orderIds as $orderId) {
                try {
                    $ecotone->sendCommandWithRouting('order.place', $orderId);
                } catch (RuntimeException) {
                }
            }

            return $ecotone->popExecutionRecording()->linesWithEveryHint;
        };

        $alone = $recordingOf(['order-1']);
        $afterAFailure = $recordingOf(['failing', 'order-1']);

        self::assertSame(
            str_replace('process 1: send command order.place, class@anonymous::place', 'process 2: send command order.place, class@anonymous::place (2 of 2)', $alone),
            array_slice($afterAFailure, (int) array_search('process 2: send command order.place, class@anonymous::place (2 of 2)', $afterAFailure, true)),
        );
    }

    /**
     * @param list<class-string> $classes
     * @param callable(): list<object> $services
     * @param callable(FlowTestSupport): mixed $run
     * @param callable(object): array $observed
     * @param list<object> $extensionObjects
     */
    private function assertSameOutcomeWithRecordingOnAndOff(array $classes, callable $services, callable $run, callable $observed, array $extensionObjects = []): void
    {
        $outcomes = [];
        foreach ([false, true] as $recording) {
            $instances = $services();
            $tracing = self::tracingConfiguration();
            $ecotone = EcotoneLite::bootstrapFlowTesting(
                $classes,
                [...$instances, ...self::recordingSpansInto(new InMemorySpanRecorder())],
                [...$extensionObjects, $recording ? $tracing->withLevel(TracingLevel::Framework) : $tracing],
                enabledModules: [],
                licenceKey: LicenceTesting::VALID_LICENCE,
            );
            try {
                $run($ecotone);
                $failure = null;
            } catch (Throwable $thrown) {
                $failure = $thrown::class . ': ' . $thrown->getMessage();
            }
            $outcomes[] = ['failure' => $failure, 'observed' => $instances === [] ? [] : $observed($instances[0])];
            if ($recording) {
                self::assertNotEmpty($ecotone->popExecutionRecording()->linesWithEveryHint);
            }
        }

        self::assertSame($outcomes[0], $outcomes[1]);
    }
}

#[Aggregate]
final class ExecutionRecordedBinaryOrder
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
