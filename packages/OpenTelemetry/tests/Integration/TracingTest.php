<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Integration;

use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;

abstract class TracingTest extends TestCase
{
    protected function prepareTracer(SpanExporterInterface $exporter): TracerInterface
    {
        $tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                $exporter
            )
        );

        return $tracerProvider->getTracer('io.opentelemetry.contrib.php');
    }
}
