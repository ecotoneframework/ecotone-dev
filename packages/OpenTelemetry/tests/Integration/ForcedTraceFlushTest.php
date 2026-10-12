<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Integration;

use ArrayObject;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\OpenTelemetry\Configuration\TracingConfiguration;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;

/**
 * licence Apache-2.0
 * @internal
 */
final class ForcedTraceFlushTest extends TracingTestCase
{
    public function test_forced_flush_refuses_a_tracer_provider_that_cannot_flush_before_any_message_is_sent(): void
    {
        $handler = new class () {
            public array $handled = [];

            #[CommandHandler('order.place')]
            public function place(string $orderId): void
            {
                $this->handled[] = $orderId;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$handler::class],
            [$handler, TracerProviderInterface::class => new NoopTracerProvider()],
            ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::TRACING_PACKAGE]),
        );

        try {
            $ecotone->sendCommandWithRouting('order.place', 'order-1');
            $this->fail('A tracer provider that cannot flush was accepted');
        } catch (ConfigurationException $exception) {
            $this->assertSame([], $handler->handled);
            $this->assertStringContainsString(NoopTracerProvider::class, $exception->getMessage());
            $this->assertStringContainsString('OpenTelemetry\SDK\Trace\TracerProviderInterface', $exception->getMessage());
            $this->assertStringContainsString('new OpenTelemetry\SDK\Trace\TracerProvider(', $exception->getMessage());
            $this->assertStringContainsString('withForceFlushOnBusExecution(false)', $exception->getMessage());
            $this->assertStringContainsString('withForceFlushOnAsynchronousMessageHandled(false)', $exception->getMessage());
        }
    }

    public function test_tracer_provider_that_cannot_flush_handles_messages_once_forced_flush_is_turned_off(): void
    {
        $handler = new class () {
            public array $handled = [];

            #[CommandHandler('order.place')]
            public function place(string $orderId): void
            {
                $this->handled[] = $orderId;
            }
        };

        EcotoneLite::bootstrapFlowTesting(
            [$handler::class],
            [$handler, TracerProviderInterface::class => new NoopTracerProvider()],
            ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::TRACING_PACKAGE])
                ->withExtensionObjects([
                    TracingConfiguration::createWithDefaults()
                        ->withForceFlushOnBusExecution(false)
                        ->withForceFlushOnAsynchronousMessageHandled(false),
                ]),
        )->sendCommandWithRouting('order.place', 'order-1');

        $this->assertSame(['order-1'], $handler->handled);
    }

    public function test_sdk_tracer_provider_flushes_spans_after_a_command_is_sent(): void
    {
        $exporter = new InMemoryExporter(new ArrayObject());
        $handler = new class () {
            public array $handled = [];

            #[CommandHandler('order.place')]
            public function place(string $orderId): void
            {
                $this->handled[] = $orderId;
            }
        };

        EcotoneLite::bootstrapFlowTesting(
            [$handler::class],
            [$handler, TracerProviderInterface::class => self::prepareTracer($exporter)],
            ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::TRACING_PACKAGE]),
        )->sendCommandWithRouting('order.place', 'order-1');

        $this->assertSame(['order-1'], $handler->handled);
        $this->assertNotEmpty($exporter->getSpans());
    }
}
