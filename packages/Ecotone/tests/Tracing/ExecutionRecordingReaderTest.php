<?php

declare(strict_types=1);

namespace Test\Ecotone\Tracing;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Api\Tracing\RecordedHint;
use Ecotone\Api\Tracing\RecordedProcess;
use Ecotone\Api\Tracing\RecordedSender;
use Ecotone\Api\Tracing\RecordedStep;
use Ecotone\Api\Tracing\Span;
use Ecotone\Api\Tracing\SpanRecorder;
use Ecotone\Api\Tracing\TracingConfiguration;
use Ecotone\Test\LicenceTesting;

/**
 * licence Apache-2.0
 * @internal
 */
final class ExecutionRecordingReaderTest extends TracingTestCase
{
    public function test_the_flow_testing_support_reads_the_recording_as_processes_in_run_order_with_their_steps_hints_and_sender(): void
    {
        $ordering = new class () {
            #[CommandHandler('order.place')]
            public function place(array $order, EventBus $eventBus): void
            {
                $eventBus->publishWithRouting('order.placed', $order);
            }

            #[Asynchronous('notifications')]
            #[EventHandler('order.placed', endpointId: 'notify')]
            public function notify(array $order): void
            {
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$ordering::class],
            [$ordering, ...self::recordingSpansInto(new InMemorySpanRecorder())],
            [SimpleMessageChannelBuilder::createQueueChannel('notifications'), self::tracingConfiguration()->withExecutionRecording()],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->sendCommandWithRouting('order.place', []);
        $ecotone->run('notifications', ExecutionPollingMetadata::createWithTestingSetup());
        $recording = $ecotone->getExecutionRecording();

        self::assertSame(
            ['send command order.place, class@anonymous::place', 'receive notifications, class@anonymous::notify'],
            array_map(fn (RecordedProcess $process): string => $process->title, $recording->processes),
        );
        self::assertNull($recording->processes[0]->sentBy);
        self::assertEquals(new RecordedSender(process: 1, step: $recording->processes[1]->sentBy->step), $recording->processes[1]->sentBy);
        self::assertSame('release collected message to notifications', $recording->processes[0]->steps[$recording->processes[1]->sentBy->step]->label);
        self::assertEquals(new RecordedStep(depth: 1, label: 'gateway CommandBus::sendWithRouting', hints: [new RecordedHint(key: 'returns', value: 'nothing', critical: false)]), $recording->processes[0]->steps[0]);
        self::assertContainsEquals(new RecordedHint(key: 'sent by', value: 'process 1, the event order.placed', critical: true), $recording->processes[1]->steps[0]->hints);
        self::assertSame(['process 1: send command order.place, class@anonymous::place', '  gateway CommandBus::sendWithRouting', '    before MessageHeadersPropagatorInterceptor::propagateHeaders'], array_slice($recording->lines, 0, 3));
        self::assertSame(['process 1: send command order.place, class@anonymous::place', '  gateway CommandBus::sendWithRouting', '    ↳ returns: nothing'], array_slice($recording->linesWithEveryHint, 0, 3));
    }

    public function test_reading_the_recording_of_an_application_that_does_not_record_its_execution_is_refused(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [],
            self::recordingSpansInto(new InMemorySpanRecorder()),
            [self::tracingConfiguration()],
            enabledModules: [],
        );

        $this->expectExceptionMessage('The execution recording was asked for, but this application does not record its execution, so there is nothing to read. Switch it on with TracingConfiguration::createWithDefaults()->withSpanRecorder(InMemorySpanRecorder::class)->withExecutionRecording() in a #[ServiceContext] or in the extension objects of EcotoneLite::bootstrapFlowTesting().');

        $ecotone->getExecutionRecording();
    }

    public function test_reading_the_recording_of_an_application_without_tracing_is_refused(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting([], [], enabledModules: []);

        $this->expectExceptionMessage('does not record its execution');

        $ecotone->getExecutionRecording();
    }

    public function test_reading_a_recording_kept_by_no_in_memory_span_recorder_is_refused(): void
    {
        $exporter = new class () implements SpanRecorder {
            public function record(Span $span): void
            {
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [],
            ['spanExporter' => $exporter],
            [TracingConfiguration::createWithDefaults()->withSpanRecorder('spanExporter')->withExecutionRecording()],
            enabledModules: [],
        );

        $this->expectExceptionMessage('The execution recording was asked for, but no InMemorySpanRecorder keeps the spans it is read from: the span recorders registered with TracingConfiguration::withSpanRecorder() send spans on without keeping them. Register InMemorySpanRecorder as well, with TracingConfiguration::withSpanRecorder(InMemorySpanRecorder::class) and an InMemorySpanRecorder service.');

        $ecotone->getExecutionRecording();
    }
}
