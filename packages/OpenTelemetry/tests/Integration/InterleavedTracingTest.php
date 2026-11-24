<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Integration;

use ArrayObject;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\OpenTelemetry\OpenTelemetryConfiguration;
use Ecotone\Api\Tracing\SpanAttribute;
use Fiber;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;

/**
 * licence Apache-2.0
 * @internal
 */
final class InterleavedTracingTest extends TracingTestCase
{
    private const ORDER_TRACE = '0af7651916cd43dd8448eb211c80319c';
    private const PAYMENT_TRACE = '4bf92f3577b34da6a3ce929d0e0e4736';

    public function test_flow_started_while_another_flow_has_open_spans_is_traced_under_its_own_tracing_id(): void
    {
        $exporter = new InMemoryExporter(new ArrayObject());
        $ecotone = $this->bootstrapOrderAndPayment($exporter);

        $suspended = new Fiber(self::withOwnTracingContext(fn () => $ecotone->sendCommandWithRouting('order.place', [], metadata: [MessageHeaders::TRACING_ID => self::ORDER_TRACE])));
        $suspended->start();
        $ecotone->sendCommandWithRouting('payment.charge', [], metadata: [MessageHeaders::TRACING_ID => self::PAYMENT_TRACE]);
        $suspended->resume();

        $this->assertEquals(
            [
                self::ORDER_TRACE => [
                    'process class@anonymous::place <- send command',
                    'process class@anonymous::whenOrderWasPlaced <- route',
                    'route <- send event',
                    'send command <- ',
                    'send event <- process class@anonymous::place',
                ],
                self::PAYMENT_TRACE => [
                    'process class@anonymous::charge <- send command',
                    'send command <- ',
                ],
            ],
            self::spanParentsPerTrace($exporter)
        );
    }

    public function test_flow_started_while_another_flow_has_open_spans_is_the_root_of_a_trace_of_its_own(): void
    {
        $exporter = new InMemoryExporter(new ArrayObject());
        $ecotone = $this->bootstrapOrderAndPayment($exporter);

        $suspended = new Fiber(self::withOwnTracingContext(fn () => $ecotone->sendCommandWithRouting('order.place', [])));
        $suspended->start();
        $ecotone->sendCommandWithRouting('payment.charge', []);
        $suspended->resume();

        $this->assertEqualsCanonicalizing(
            [
                [
                    'process class@anonymous::place <- send command',
                    'process class@anonymous::whenOrderWasPlaced <- route',
                    'route <- send event',
                    'send command <- ',
                    'send event <- process class@anonymous::place',
                ],
                [
                    'process class@anonymous::charge <- send command',
                    'send command <- ',
                ],
            ],
            array_values(self::spanParentsPerTrace($exporter))
        );
        $this->assertSame(['send command', 'send command'], self::namesOfTraceRoots($exporter));
    }

    private function bootstrapOrderAndPayment(InMemoryExporter $exporter): FlowTestSupport
    {
        $handlers = new class () {
            #[CommandHandler('order.place')]
            public function place(array $command, EventBus $eventBus): void
            {
                Fiber::suspend();
                $eventBus->publish(new OrderWasPlacedInInterleavedFlow());
            }

            #[EventHandler]
            public function whenOrderWasPlaced(OrderWasPlacedInInterleavedFlow $event): void
            {
            }

            #[CommandHandler('payment.charge')]
            public function charge(array $command): void
            {
            }
        };

        return EcotoneLite::bootstrapFlowTesting(
            [],
            [$handlers, TracerProviderInterface::class => self::prepareTracer($exporter)],
            extensionObjects: [OpenTelemetryConfiguration::createWithDefaults()],
            enabledModules: [ModulePackageList::TRACING_PACKAGE],
        );
    }

    private static function withOwnTracingContext(callable $execution): callable
    {
        return static function () use ($execution): void {
            $scope = Context::getRoot()->activate();
            try {
                $execution();
            } finally {
                $scope->detach();
            }
        };
    }

    private static function spanParentsPerTrace(InMemoryExporter $exporter): array
    {
        $spans = self::spans($exporter);
        $namesById = [];
        foreach ($spans as $span) {
            $namesById[$span->getTraceId()][$span->getSpanId()] = self::nameOf($span);
        }

        $parentsPerTrace = [];
        foreach ($spans as $span) {
            $parentName = $span->getParentContext()->isValid() ? ($namesById[$span->getTraceId()][$span->getParentSpanId()] ?? '') : '';
            $parentsPerTrace[$span->getTraceId()][] = self::nameOf($span) . ' <- ' . $parentName;
        }
        foreach ($parentsPerTrace as &$parents) {
            sort($parents);
        }

        return $parentsPerTrace;
    }

    private static function namesOfTraceRoots(InMemoryExporter $exporter): array
    {
        $names = [];
        foreach (self::spans($exporter) as $span) {
            if (($span->getAttributes()->toArray()[SpanAttribute::TRACE_ROOT] ?? false) === true) {
                $names[] = self::nameOf($span);
            }
        }

        return $names;
    }

    private static function spans(InMemoryExporter $exporter): array
    {
        return array_values(array_filter(
            $exporter->getSpans(),
            static fn (SpanDataInterface $span) => ! str_starts_with($span->getName(), 'process MessageHeadersPropagatorInterceptor::'),
        ));
    }

    private static function nameOf(SpanDataInterface $span): string
    {
        return str_starts_with($span->getName(), 'route ') ? 'route' : $span->getName();
    }
}

final class OrderWasPlacedInInterleavedFlow
{
}
