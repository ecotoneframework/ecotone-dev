<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Integration;

use ArrayObject;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use RuntimeException;

/**
 * licence Apache-2.0
 * @internal
 */
final class SwallowedFailureTracingTest extends TracingTestCase
{
    public function test_a_consumer_whose_handler_failure_went_to_the_error_channel_is_exported_as_failed(): void
    {
        $exporter = new InMemoryExporter(new ArrayObject());
        $orders = new class () {
            #[Asynchronous('async_channel')]
            #[CommandHandler('order.place', endpointId: 'order.placing')]
            public function place(string $orderId): void
            {
                throw new RuntimeException('Placing failed');
            }
        };

        EcotoneLite::bootstrapFlowTesting(
            [$orders::class],
            [$orders, TracerProviderInterface::class => self::prepareTracer($exporter)],
            [SimpleMessageChannelBuilder::createQueueChannel('async_channel'), ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel('errorChannel')],
            enabledModules: [ModulePackageList::TRACING_PACKAGE],
        )
            ->sendCommandWithRouting('order.place', '1')
            ->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false));

        $statuses = [];
        foreach ($exporter->getSpans() as $span) {
            $statuses[$span->getName()] = $span->getStatus()->getCode();
        }
        $this->assertSame(StatusCode::STATUS_ERROR, $statuses['receive async_channel']);
        $this->assertSame(StatusCode::STATUS_ERROR, $statuses['process ' . substr($orders::class, 0, strpos($orders::class, "\0")) . '::place']);
    }
}
