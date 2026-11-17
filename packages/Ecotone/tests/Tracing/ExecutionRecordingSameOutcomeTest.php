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
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\CollectedMessages;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Test\Flow\Snapshot\FlowExecution;
use Ecotone\Test\LicenceTesting;
use Throwable;

/**
 * licence Apache-2.0
 * @internal
 */
final class ExecutionRecordingSameOutcomeTest extends TracingTestCase
{
    public function test_a_routing_key_that_is_not_valid_utf8_ends_the_same_with_recording_on_and_off(): void
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

    public function test_a_collector_keyed_by_a_binary_value_ends_the_same_with_recording_on_and_off(): void
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

    public function test_an_aggregate_with_a_binary_identifier_ends_the_same_with_recording_on_and_off(): void
    {
        $run = function (FlowTestSupport $ecotone): void {
            $ecotone->sendCommandWithRouting('order.place', "\xFF\xFE");
            $ecotone->sendCommandWithRouting('order.confirm', "\xFF\xFE");
            $ecotone->run('orders', ExecutionPollingMetadata::createWithTestingSetup());
        };

        $this->assertSameOutcomeWithRecordingOnAndOff([ExecutionRecordedBinaryOrder::class], fn (): array => [], $run, fn (): array => [], [SimpleMessageChannelBuilder::createQueueChannel('orders')]);
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
            $recorder = new InMemorySpanRecorder();
            $instances = $services();
            $tracing = self::tracingConfiguration();
            $ecotone = EcotoneLite::bootstrapFlowTesting(
                $classes,
                [...$instances, ...self::recordingSpansInto($recorder)],
                [...$extensionObjects, $recording ? $tracing->withExecutionRecording() : $tracing],
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
                self::assertNotEmpty(FlowExecution::of($recorder->recordedSpans())->lines(true));
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
