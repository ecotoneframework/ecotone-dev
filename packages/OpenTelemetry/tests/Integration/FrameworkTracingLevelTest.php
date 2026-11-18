<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Integration;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Api\Tracing\RecordedProcess;
use Ecotone\Api\Tracing\TracingConfiguration;
use Ecotone\Api\Tracing\TracingLevel;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;

/**
 * licence Apache-2.0
 * @internal
 */
final class FrameworkTracingLevelTest extends TracingTestCase
{
    public function test_the_framework_level_records_the_execution_of_a_flow_traced_through_open_telemetry(): void
    {
        $ecotone = $this->placedAndNotified(new InMemoryExporter(), TracingConfiguration::createWithDefaults()->withSpanRecorder(InMemorySpanRecorder::class)->withLevel(TracingLevel::Framework));

        $processes = $ecotone->getExecutionRecording()->processes;

        self::assertSame(
            ['send command order.place, class@anonymous::place', 'receive notifications, class@anonymous::notify'],
            array_map(fn (RecordedProcess $process): string => $process->title, $processes),
        );
        self::assertSame(1, $processes[1]->sentBy?->process);
    }

    public function test_the_framework_level_exports_the_same_spans_to_open_telemetry_as_the_application_level_and_only_it_adds_execution_attributes(): void
    {
        $exported = [];
        foreach (['application' => TracingConfiguration::createWithDefaults(), 'framework' => TracingConfiguration::createWithDefaults()->withSpanRecorder(InMemorySpanRecorder::class)->withLevel(TracingLevel::Framework)] as $level => $tracing) {
            $exporter = new InMemoryExporter();
            $this->placedAndNotified($exporter, $tracing);
            $exported[$level] = $exporter->getSpans();
        }
        $shapeOf = function (array $spans): array {
            $nameOf = [];
            foreach ($spans as $span) {
                $nameOf[$span->getSpanId()] = $span->getName();
            }

            return array_map(fn (ImmutableSpan $span): array => [$span->getName(), $span->getKind(), $span->getStatus()->getCode(), $nameOf[$span->getParentSpanId()] ?? null], $spans);
        };
        $executionAttributesOf = fn (array $spans): array => array_values(array_unique(array_merge(...array_map(fn (ImmutableSpan $span): array => array_values(array_filter(array_keys($span->getAttributes()->toArray()), fn (string $key): bool => str_starts_with($key, 'ecotone.execution.'))), $spans))));

        self::assertNotEmpty($exported['application']);
        self::assertSame($shapeOf($exported['application']), $shapeOf($exported['framework']));
        self::assertSame([], $executionAttributesOf($exported['application']));
        self::assertNotSame([], $executionAttributesOf($exported['framework']));
    }

    public function test_the_framework_level_with_a_tracer_provider_but_no_span_recorder_is_refused_at_bootstrap(): void
    {
        $this->expectExceptionMessage('The framework tracing level is switched on with TracingConfiguration::withLevel(TracingLevel::Framework), but no span recorder is registered, so the execution steps it records would reach nothing: a tracer, such as the OpenTelemetry one, exports spans only. Register one with TracingConfiguration::withSpanRecorder(), for example InMemorySpanRecorder in tests, or stay at the default TracingLevel::Application.');

        $this->placedAndNotified(new InMemoryExporter(), TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework));
    }

    private function placedAndNotified(InMemoryExporter $exporter, TracingConfiguration $tracing): FlowTestSupport
    {
        $handlers = new class () {
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
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$handlers::class],
            [$handlers, TracerProviderInterface::class => self::prepareTracer($exporter), InMemorySpanRecorder::class => new InMemorySpanRecorder()],
            [SimpleMessageChannelBuilder::createQueueChannel('notifications'), $tracing],
            enabledModules: [ModulePackageList::TRACING_PACKAGE],
        );
        $ecotone->sendCommandWithRouting('order.place', 'coffee');
        $ecotone->run('notifications', ExecutionPollingMetadata::createWithTestingSetup());

        return $ecotone;
    }
}
