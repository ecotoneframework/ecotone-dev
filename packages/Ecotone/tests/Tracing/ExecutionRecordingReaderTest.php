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
use Ecotone\Api\Tracing\TracingLevel;
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
            [$ordering],
            [SimpleMessageChannelBuilder::createQueueChannel('notifications'), TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework)],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->sendCommandWithRouting('order.place', []);
        $ecotone->run('notifications', ExecutionPollingMetadata::createWithTestingSetup());
        $recording = $ecotone->popExecutionRecording();

        self::assertSame(
            ['send command order.place, class@anonymous::place', 'receive notifications, class@anonymous::notify'],
            array_map(fn (RecordedProcess $process): string => $process->title, $recording->processes),
        );
        self::assertNull($recording->processes[0]->sentBy);
        self::assertEquals(new RecordedSender(processNumber: 1, stepNumber: $recording->processes[1]->sentBy->stepNumber), $recording->processes[1]->sentBy);
        self::assertSame('release collected message to notifications', $recording->processes[0]->steps[$recording->processes[1]->sentBy->stepNumber - 1]->label);
        self::assertEquals(new RecordedStep(depth: 1, label: 'gateway CommandBus::sendWithRouting', hints: [new RecordedHint(key: 'returns', value: 'nothing', shownInLines: false)]), $recording->processes[0]->steps[0]);
        self::assertContainsEquals(new RecordedHint(key: 'sent by', value: 'process 1, the event order.placed', shownInLines: true), $recording->processes[1]->steps[0]->hints);
        self::assertSame(['process 1: send command order.place, class@anonymous::place', '  gateway CommandBus::sendWithRouting', '    before MessageHeadersPropagatorInterceptor::propagateHeaders'], array_slice($recording->lines, 0, 3));
        self::assertSame(['process 1: send command order.place, class@anonymous::place', '  gateway CommandBus::sendWithRouting', '    ↳ returns: nothing'], array_slice($recording->linesWithEveryHint, 0, 3));
    }

    public function test_in_flow_testing_the_framework_level_alone_records_the_execution(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$this->ordering()::class],
            [$this->ordering()],
            [SimpleMessageChannelBuilder::createQueueChannel('notifications'), TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework)],
            enabledModules: [],
        );

        $ecotone->sendCommandWithRouting('order.place', []);
        $ecotone->run('notifications', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(
            ['send command order.place, class@anonymous::place', 'receive notifications, class@anonymous::notify'],
            array_map(fn (RecordedProcess $process): string => $process->title, $ecotone->popExecutionRecording()->processes),
        );
    }

    public function test_popping_the_recording_returns_what_ran_since_the_last_pop(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$this->ordering()::class],
            [$this->ordering()],
            [SimpleMessageChannelBuilder::createQueueChannel('notifications'), TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework)],
            enabledModules: [],
        );

        $ecotone->sendCommandWithRouting('order.place', []);
        $first = $ecotone->popExecutionRecording();
        $ecotone->run('notifications', ExecutionPollingMetadata::createWithTestingSetup());
        $second = $ecotone->popExecutionRecording();

        self::assertSame(['send command order.place, class@anonymous::place'], array_map(fn (RecordedProcess $process): string => $process->title, $first->processes));
        self::assertSame(['receive notifications, class@anonymous::notify'], array_map(fn (RecordedProcess $process): string => $process->title, $second->processes));
        self::assertSame([], $ecotone->popExecutionRecording()->processes);
    }

    public function test_reading_the_recording_at_the_application_tracing_level_is_refused(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [],
            self::recordingSpansInto(new InMemorySpanRecorder()),
            [self::tracingConfiguration()],
            enabledModules: [],
        );

        $this->expectExceptionMessage('popExecutionRecording() was called, but this application does not trace at TracingLevel::Framework, so its execution is not recorded and there is nothing to read. The recording is read only in a flow test, EcotoneLite::bootstrapFlowTesting(), at the framework level: return TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework) from a #[ServiceContext], or pass it in the extension objects of EcotoneLite::bootstrapFlowTesting().');

        $ecotone->popExecutionRecording();
    }

    public function test_reading_the_recording_of_an_application_without_tracing_is_refused(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting([], [], enabledModules: []);

        $this->expectExceptionMessage('does not trace at TracingLevel::Framework');

        $ecotone->popExecutionRecording();
    }

    public function test_a_test_exporting_spans_to_its_own_span_recorder_reads_the_recording(): void
    {
        $exporter = new class () implements SpanRecorder {
            public function record(Span $span): void
            {
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$this->ordering()::class],
            [$this->ordering(), 'spanExporter' => $exporter],
            [SimpleMessageChannelBuilder::createQueueChannel('notifications'), TracingConfiguration::createWithDefaults()->withSpanRecorder('spanExporter')->withLevel(TracingLevel::Framework)],
            enabledModules: [],
        );

        $ecotone->sendCommandWithRouting('order.place', []);

        self::assertSame(['send command order.place, class@anonymous::place'], array_map(fn (RecordedProcess $process): string => $process->title, $ecotone->popExecutionRecording()->processes));
    }

    public function test_popping_the_recording_leaves_the_spans_in_the_in_memory_span_recorder_the_test_registered(): void
    {
        $recorder = new InMemorySpanRecorder();
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$this->ordering()::class],
            [$this->ordering(), ...self::recordingSpansInto($recorder)],
            [SimpleMessageChannelBuilder::createQueueChannel('notifications'), self::tracingConfiguration()->withLevel(TracingLevel::Framework)],
            enabledModules: [],
        );

        $ecotone->sendCommandWithRouting('order.place', []);
        $spansBeforeThePop = count($recorder->recordedSpans());
        $ecotone->popExecutionRecording();

        self::assertGreaterThan(0, $spansBeforeThePop);
        self::assertCount($spansBeforeThePop, $recorder->recordedSpans());
    }

    private function ordering(): object
    {
        return new class () {
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
    }
}
