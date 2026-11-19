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
use Ecotone\Api\Tracing\RecordedProcess;
use Ecotone\Api\Tracing\TracingConfiguration;
use Ecotone\Api\Tracing\TracingLevel;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * licence Apache-2.0
 * @internal
 */
final class FrameworkTracingLevelTest extends TracingTestCase
{
    public function test_the_framework_level_records_the_execution_of_a_flow_traced_through_open_telemetry(): void
    {
        $ecotone = $this->placedAndNotified(new InMemoryExporter(), TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework));

        $processes = $ecotone->popExecutionRecording()->processes;

        self::assertSame(
            ['send command order.place, class@anonymous::place', 'receive notifications, class@anonymous::notify'],
            array_map(fn (RecordedProcess $process): string => $process->title, $processes),
        );
        self::assertSame(1, $processes[1]->sentBy?->processNumber);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function flows(): iterable
    {
        yield 'a command publishing an event handled asynchronously' => ['placedAndNotified'];
        yield 'a command whose handler fails' => ['refused'];
    }

    #[DataProvider('flows')]
    public function test_the_framework_level_exports_the_same_spans_to_open_telemetry_as_the_application_level_and_only_it_adds_execution_attributes(string $flow): void
    {
        $exported = [];
        foreach (['application' => TracingConfiguration::createWithDefaults(), 'framework' => TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework)] as $level => $tracing) {
            $exporter = new InMemoryExporter();
            $this->{$flow}($exporter, $tracing);
            $exported[$level] = $exporter->getSpans();
        }
        $stable = fn (mixed $value): mixed => is_string($value) ? (string) preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|\b[0-9a-f]{32}\b|\b[0-9a-f]{16}\b/', '{id}', $value) : $value;
        $withoutExecution = function (array $attributes) use ($stable): array {
            $kept = [];
            foreach ($attributes as $key => $value) {
                if (! str_starts_with((string) $key, 'ecotone.execution.')) {
                    $kept[$key] = in_array($key, ['timestamp', 'exception.stacktrace'], true) ? '{' . $key . '}' : $stable($value);
                }
            }

            return $kept;
        };
        $shapeOf = function (array $spans) use ($withoutExecution, $stable): array {
            $nameOf = [];
            foreach ($spans as $span) {
                $nameOf[$span->getSpanId()] = $span->getName();
            }

            return array_map(fn (ImmutableSpan $span): array => [
                'name' => $span->getName(),
                'kind' => $span->getKind(),
                'status' => $span->getStatus()->getCode(),
                'parent' => $nameOf[$span->getParentSpanId()] ?? null,
                'attributes' => $withoutExecution($span->getAttributes()->toArray()),
                'events' => array_map(fn ($event): array => [$stable($event->getName()), $withoutExecution($event->getAttributes()->toArray())], $span->getEvents()),
            ], $spans);
        };
        $executionAttributesOf = fn (array $spans): array => array_values(array_unique(array_merge(...array_map(fn (ImmutableSpan $span): array => array_values(array_filter(array_keys($span->getAttributes()->toArray()), fn (string $key): bool => str_starts_with($key, 'ecotone.execution.'))), $spans))));

        self::assertNotEmpty($exported['application']);
        self::assertSame($shapeOf($exported['application']), $shapeOf($exported['framework']));
        self::assertSame([], $executionAttributesOf($exported['application']));
        self::assertNotSame([], $executionAttributesOf($exported['framework']));
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
            [$handlers, TracerProviderInterface::class => self::prepareTracer($exporter)],
            [SimpleMessageChannelBuilder::createQueueChannel('notifications'), $tracing],
            enabledModules: [ModulePackageList::TRACING_PACKAGE],
        );
        $ecotone->sendCommandWithRouting('order.place', 'coffee');
        $ecotone->run('notifications', ExecutionPollingMetadata::createWithTestingSetup());

        return $ecotone;
    }

    private function refused(InMemoryExporter $exporter, TracingConfiguration $tracing): void
    {
        $handlers = new class () {
            #[CommandHandler('order.refuse')]
            public function refuse(string $order): void
            {
                throw new RuntimeException('the order is refused');
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$handlers::class],
            [$handlers, TracerProviderInterface::class => self::prepareTracer($exporter)],
            [$tracing],
            enabledModules: [ModulePackageList::TRACING_PACKAGE],
        );
        try {
            $ecotone->sendCommandWithRouting('order.refuse', 'coffee');
        } catch (RuntimeException) {
        }
    }
}
