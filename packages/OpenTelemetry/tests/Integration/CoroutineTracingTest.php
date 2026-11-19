<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Integration;

use ArrayObject;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\OpenTelemetry\OpenTelemetryConfiguration;
use OpenSwoole\Coroutine;
use OpenSwoole\Runtime;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextStorage;
use OpenTelemetry\Context\ContextStorageInterface;
use OpenTelemetry\Contrib\Context\Swoole\SwooleContextStorage;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Throwable;

/**
 * licence Apache-2.0
 * @internal
 */
#[RequiresPhpExtension('openswoole')]
final class CoroutineTracingTest extends TracingTestCase
{
    private const ORDER_TRACE = '0af7651916cd43dd8448eb211c80319c';
    private const PAYMENT_TRACE = '4bf92f3577b34da6a3ce929d0e0e4736';
    private const REFUND_TRACE = '7c1d5b0f9e2a4c3b8d6e1f0a9b8c7d6e';

    private ContextStorageInterface $contextStorageOfTheProcess;

    protected function setUp(): void
    {
        $this->contextStorageOfTheProcess = Context::storage();
        Context::setStorage(new SwooleContextStorage(new ContextStorage()));
    }

    protected function tearDown(): void
    {
        Context::setStorage($this->contextStorageOfTheProcess);
    }

    public function test_flows_handled_at_once_on_coroutines_are_each_traced_under_their_own_tracing_id(): void
    {
        $exporter = new InMemoryExporter(new ArrayObject());
        $ecotone = $this->bootstrapOrders($exporter);

        $this->runCoroutines(
            fn () => $ecotone->sendCommandWithRouting('order.place', ['pauseSeconds' => 0.06], metadata: [MessageHeaders::TRACING_ID => self::ORDER_TRACE]),
            fn () => $ecotone->sendCommandWithRouting('order.place', ['pauseSeconds' => 0.02], metadata: [MessageHeaders::TRACING_ID => self::PAYMENT_TRACE]),
            fn () => $ecotone->sendCommandWithRouting('order.place', ['pauseSeconds' => 0.04], metadata: [MessageHeaders::TRACING_ID => self::REFUND_TRACE]),
        );

        $flow = [
            'process class@anonymous::place <- send command',
            'process class@anonymous::whenOrderWasPlaced <- route',
            'route <- send event',
            'send command <- ',
            'send event <- process class@anonymous::place',
        ];
        $this->assertEquals(
            [self::ORDER_TRACE => $flow, self::PAYMENT_TRACE => $flow, self::REFUND_TRACE => $flow],
            self::spanParentsPerTrace($exporter)
        );
    }

    public function test_flows_handled_at_once_on_coroutines_without_a_tracing_id_are_each_a_trace_of_their_own(): void
    {
        $exporter = new InMemoryExporter(new ArrayObject());
        $ecotone = $this->bootstrapOrders($exporter);

        $this->runCoroutines(
            fn () => $ecotone->sendCommandWithRouting('order.place', ['pauseSeconds' => 0.06]),
            fn () => $ecotone->sendCommandWithRouting('order.place', ['pauseSeconds' => 0.02]),
            fn () => $ecotone->sendCommandWithRouting('order.place', ['pauseSeconds' => 0.04]),
        );

        $flow = [
            'process class@anonymous::place <- send command',
            'process class@anonymous::whenOrderWasPlaced <- route',
            'route <- send event',
            'send command <- ',
            'send event <- process class@anonymous::place',
        ];
        $this->assertSame([$flow, $flow, $flow], array_values(self::spanParentsPerTrace($exporter)));
    }

    public function test_event_published_by_a_coroutine_a_handler_started_is_traced_under_that_handler(): void
    {
        $exporter = new InMemoryExporter(new ArrayObject());
        $ecotone = $this->bootstrapOrders($exporter);

        $this->runCoroutines(
            fn () => $ecotone->sendCommandWithRouting('order.placeFromCoroutine', ['pauseSeconds' => 0.04], metadata: [MessageHeaders::TRACING_ID => self::ORDER_TRACE]),
            fn () => $ecotone->sendCommandWithRouting('order.placeFromCoroutine', ['pauseSeconds' => 0.02], metadata: [MessageHeaders::TRACING_ID => self::PAYMENT_TRACE]),
        );

        $flow = [
            'process class@anonymous::placeFromCoroutine <- send command',
            'process class@anonymous::whenOrderWasPlaced <- route',
            'route <- send event',
            'send command <- ',
            'send event <- process class@anonymous::placeFromCoroutine',
        ];
        $this->assertEquals([self::ORDER_TRACE => $flow, self::PAYMENT_TRACE => $flow], self::spanParentsPerTrace($exporter));
    }

    public function test_messages_consumed_at_once_on_consumer_coroutines_continue_the_trace_of_the_flow_that_sent_them(): void
    {
        $exporter = new InMemoryExporter(new ArrayObject());
        $ecotone = $this->bootstrapOrders($exporter);
        $ecotone->sendCommandWithRouting('order.ship', ['pauseSeconds' => 0.06], metadata: [MessageHeaders::TRACING_ID => self::ORDER_TRACE]);
        $ecotone->sendCommandWithRouting('order.ship', ['pauseSeconds' => 0.02], metadata: [MessageHeaders::TRACING_ID => self::PAYMENT_TRACE]);
        $ecotone->sendCommandWithRouting('order.ship', ['pauseSeconds' => 0.04], metadata: [MessageHeaders::TRACING_ID => self::REFUND_TRACE]);

        $ecotone->run('shipping', ExecutionPollingMetadata::createWithFinishWhenNoMessages()->withCoroutines(3));

        $tracesOfHandledMessages = [];
        foreach (self::spanParentsPerTrace($exporter) as $traceId => $spans) {
            $tracesOfHandledMessages[$traceId] = array_values(array_filter($spans, static fn (string $span) => str_contains($span, '::ship') || str_contains($span, '::whenOrderWasPlaced') || str_starts_with($span, 'send event')));
        }
        $handledMessage = [
            'process class@anonymous::ship <- receive shipping',
            'process class@anonymous::whenOrderWasPlaced <- route',
            'send event <- process class@anonymous::ship',
        ];
        $this->assertEquals(
            [self::ORDER_TRACE => $handledMessage, self::PAYMENT_TRACE => $handledMessage, self::REFUND_TRACE => $handledMessage],
            $tracesOfHandledMessages
        );
    }

    private function bootstrapOrders(InMemoryExporter $exporter): FlowTestSupport
    {
        $handlers = new class () {
            #[CommandHandler('order.place')]
            public function place(array $command, EventBus $eventBus): void
            {
                usleep((int) ($command['pauseSeconds'] * 1_000_000));
                $eventBus->publish(new OrderWasPlacedOnCoroutine());
            }

            #[CommandHandler('order.placeFromCoroutine')]
            public function placeFromCoroutine(array $command, EventBus $eventBus): void
            {
                $published = new Coroutine\Channel(1);
                Coroutine::create(static function () use ($command, $eventBus, $published): void {
                    usleep((int) ($command['pauseSeconds'] * 1_000_000));
                    $eventBus->publish(new OrderWasPlacedOnCoroutine());
                    $published->push(true);
                });
                $published->pop();
            }

            #[Asynchronous('shipping')]
            #[CommandHandler('order.ship', endpointId: 'order.ship.endpoint')]
            public function ship(array $command, EventBus $eventBus): void
            {
                usleep((int) ($command['pauseSeconds'] * 1_000_000));
                $eventBus->publish(new OrderWasPlacedOnCoroutine());
            }

            #[EventHandler]
            public function whenOrderWasPlaced(OrderWasPlacedOnCoroutine $event): void
            {
            }
        };

        return EcotoneLite::bootstrapFlowTesting(
            [$handlers::class],
            [$handlers, TracerProviderInterface::class => self::prepareTracer($exporter)],
            extensionObjects: [OpenTelemetryConfiguration::createWithDefaults(), SimpleMessageChannelBuilder::createQueueChannel('shipping')],
            enabledModules: [ModulePackageList::TRACING_PACKAGE],
        );
    }

    private function runCoroutines(callable ...$coroutines): void
    {
        $failure = null;
        Runtime::enableCoroutine(Runtime::HOOK_ALL);
        try {
            Coroutine::run(static function () use ($coroutines, &$failure): void {
                foreach ($coroutines as $coroutine) {
                    Coroutine::create(static function () use ($coroutine, &$failure): void {
                        try {
                            $coroutine();
                        } catch (Throwable $exception) {
                            $failure ??= $exception;
                        }
                    });
                }
            });
        } finally {
            Runtime::enableCoroutine(0);
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    private static function spanParentsPerTrace(InMemoryExporter $exporter): array
    {
        $spans = $exporter->getSpans();
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

    private static function nameOf(SpanDataInterface $span): string
    {
        return str_starts_with($span->getName(), 'route ') ? 'route' : $span->getName();
    }
}

final class OrderWasPlacedOnCoroutine
{
}
