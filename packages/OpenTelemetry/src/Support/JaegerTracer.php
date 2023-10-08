<?php

declare(strict_types=1);

namespace Ecotone\OpenTelemetry\Support;

use OpenTelemetry\API\Common\Signal\Signals;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Contrib\Grpc\GrpcTransportFactory;
use OpenTelemetry\Contrib\Otlp\OtlpUtil;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

final class JaegerTracer
{
    public static function create(string $endpoint = 'http://localhost:4317'): TracerProviderInterface
    {
        $transport = (new GrpcTransportFactory())->create($endpoint . OtlpUtil::method(Signals::TRACE));

        $exporter = new SpanExporter($transport);

        return new TracerProvider(
            new BatchSpanProcessor(
                $exporter,
                ClockFactory::getDefault()
            )
        );
    }
}
