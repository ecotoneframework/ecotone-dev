<?php

declare(strict_types=1);

namespace App\Infrastructure;

use Ecotone\Api\OpenTelemetry\DropDatabaseCallsOutsideAnyTrace;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Util\ShutdownHandler;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\SdkAutoloader;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

final class OtlpTracerProviderFactory
{
    public static function registerAsGlobalTracerProvider(): void
    {
        Globals::registerInitializer(static function (Configurator $configurator): Configurator {
            $otlpEndpoint = getenv('OTEL_EXPORTER_OTLP_ENDPOINT');
            if (Sdk::isDisabled() || ! SdkAutoloader::isEnabled() || SdkAutoloader::isExcludedUrl() || SdkAutoloader::isExcludedRequestHeader() || ! is_string($otlpEndpoint) || $otlpEndpoint === '') {
                return $configurator;
            }

            return $configurator->withTracerProvider(self::create($otlpEndpoint));
        });
    }

    private static function create(string $otlpEndpoint): TracerProviderInterface
    {
        $exporter = new SpanExporter((new OtlpHttpTransportFactory())->create(rtrim($otlpEndpoint, '/') . '/v1/traces', ContentTypes::PROTOBUF));
        $tracerProvider = TracerProvider::builder()
            ->addSpanProcessor(BatchSpanProcessor::builder($exporter)->build())
            ->setSampler(DropDatabaseCallsOutsideAnyTrace::createWithDefaults())
            ->setResource(ResourceInfoFactory::defaultResource())
            ->build();
        ShutdownHandler::register($tracerProvider->shutdown(...));

        return $tracerProvider;
    }
}
