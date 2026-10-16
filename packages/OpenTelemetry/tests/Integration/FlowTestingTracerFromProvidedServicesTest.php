<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Integration;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Lite\EcotoneLite;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;

/**
 * licence Apache-2.0
 * @internal
 */
final class FlowTestingTracerFromProvidedServicesTest extends TracingTestCase
{
    public function test_a_flow_test_without_a_tracer_provider_handles_messages_untraced(): void
    {
        $orders = $this->orders();

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$orders::class],
            [$orders],
            enabledModules: [ModulePackageList::TRACING_PACKAGE],
        );

        self::assertSame(['coffee'], $ecotone->sendCommandWithRouting('order.place', 'coffee')->sendQueryWithRouting('order.placed'));
    }

    public function test_a_flow_test_given_a_tracer_provider_traces_through_it(): void
    {
        $orders = $this->orders();
        $exporter = new InMemoryExporter();

        EcotoneLite::bootstrapFlowTesting(
            [$orders::class],
            [$orders, TracerProviderInterface::class => self::prepareTracer($exporter)],
            enabledModules: [ModulePackageList::TRACING_PACKAGE],
        )->sendCommandWithRouting('order.place', 'coffee');

        self::assertNotEmpty($exporter->getSpans());
    }

    private function orders(): object
    {
        return new class () {
            private array $placed = [];

            #[CommandHandler('order.place')]
            public function place(string $order): void
            {
                $this->placed[] = $order;
            }

            #[QueryHandler('order.placed')]
            public function placed(): array
            {
                return $this->placed;
            }
        };
    }
}
