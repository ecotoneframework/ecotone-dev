<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Integration;

use ArrayObject;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\OpenTelemetry\DropDatabaseCallsOutsideAnyTrace;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

/**
 * licence Apache-2.0
 * @internal
 */
final class DropDatabaseCallsOutsideAnyTraceTest extends TracingTestCase
{
    public function test_a_database_call_made_while_a_message_is_handled_is_traced_inside_its_flow(): void
    {
        $exporter = new InMemoryExporter(new ArrayObject());
        $tracerProvider = new TracerProvider(new SimpleSpanProcessor($exporter), DropDatabaseCallsOutsideAnyTrace::createWithDefaults());
        $handler = new class ($tracerProvider) {
            public function __construct(private TracerProviderInterface $tracerProvider)
            {
            }

            #[CommandHandler('order.place')]
            public function place(): void
            {
                $this->tracerProvider->getTracer('pdo')->spanBuilder('PDO::prepare')->startSpan()->end();
            }
        };

        EcotoneLite::bootstrapFlowTesting(
            [$handler::class],
            [$handler, TracerProviderInterface::class => $tracerProvider],
            enabledModules: [ModulePackageList::TRACING_PACKAGE],
        )->sendCommandWithRouting('order.place');

        self::compareTreesByDetails(
            [
                [
                    'details' => ['name' => 'send command'],
                    'children' => [
                        [
                            'details' => ['name' => 'process class@anonymous::place'],
                            'children' => [
                                ['details' => ['name' => 'PDO::prepare'], 'children' => []],
                            ],
                        ],
                    ],
                ],
            ],
            self::buildTree($exporter),
        );
    }

    public function test_a_database_call_outside_any_trace_is_dropped(): void
    {
        $exporter = new InMemoryExporter(new ArrayObject());
        $tracerProvider = new TracerProvider(new SimpleSpanProcessor($exporter), DropDatabaseCallsOutsideAnyTrace::createWithDefaults());

        foreach (['PDO::prepare', 'PDOStatement::execute', 'sql SELECT', 'consumer poll'] as $spanName) {
            $tracerProvider->getTracer('worker')->spanBuilder($spanName)->startSpan()->end();
        }

        self::assertSame(['consumer poll'], array_map(static fn (SpanDataInterface $span): string => $span->getName(), $exporter->getSpans()));
    }

    public function test_the_span_names_counted_as_database_calls_can_be_replaced(): void
    {
        $exporter = new InMemoryExporter(new ArrayObject());
        $tracerProvider = new TracerProvider(new SimpleSpanProcessor($exporter), DropDatabaseCallsOutsideAnyTrace::createWithDefaults()->withSpanNamePrefixes(['redis ']));

        foreach (['redis GET', 'PDO::prepare'] as $spanName) {
            $tracerProvider->getTracer('worker')->spanBuilder($spanName)->startSpan()->end();
        }

        self::assertSame(['PDO::prepare'], array_map(static fn (SpanDataInterface $span): string => $span->getName(), $exporter->getSpans()));
    }

    public function test_a_span_whose_parent_was_dropped_is_dropped_with_it(): void
    {
        $exporter = new InMemoryExporter(new ArrayObject());
        $tracerProvider = new TracerProvider(new SimpleSpanProcessor($exporter), DropDatabaseCallsOutsideAnyTrace::createWithDefaults());
        $tracer = $tracerProvider->getTracer('worker');

        $statement = $tracer->spanBuilder('PDO::prepare')->startSpan();
        $scope = $statement->activate();
        $tracer->spanBuilder('fetch rows')->startSpan()->end();
        $scope->detach();
        $statement->end();

        self::assertSame([], $exporter->getSpans());
    }
}
