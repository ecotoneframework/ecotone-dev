<?php

declare(strict_types=1);

namespace App\Infrastructure;

use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Util\ShutdownHandler;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\ResourceAttributes;

final class OtlpTracerProviderFactory
{
    public static function create(string $otlpEndpoint, string $serviceName): TracerProviderInterface
    {
        $exporter = new SpanExporter((new OtlpHttpTransportFactory())->create(rtrim($otlpEndpoint, '/') . '/v1/traces', ContentTypes::PROTOBUF));
        $tracerProvider = TracerProvider::builder()
            ->addSpanProcessor(BatchSpanProcessor::builder($exporter)->build())
            ->setResource(ResourceInfoFactory::defaultResource()->merge(ResourceInfo::create(Attributes::create([ResourceAttributes::SERVICE_NAME => $serviceName]))))
            ->build();
        ShutdownHandler::register($tracerProvider->shutdown(...));

        return $tracerProvider;
    }
}
