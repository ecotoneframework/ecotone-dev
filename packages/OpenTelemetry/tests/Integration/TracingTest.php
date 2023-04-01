<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Integration;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use OpenTelemetry\API\Common\Signal\Signals;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Contrib\Grpc\GrpcTransportFactory;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\OtlpUtil;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Log\LoggerHolder;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use OpenTelemetry\SDK\Common\Util\ShutdownHandler;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\TracerProviderFactory;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow\CreateMerchant;
use Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow\Merchant;
use Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow\MerchantSubscriber;
use Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow\User;

final class TracingTest extends TestCase
{
    public function test_command_event_command_flow()
    {
        LoggerHolder::set(new Logger('otlp-example', [new StreamHandler('php://stderr')]));

        putenv('OTEL_SDK_DISABLED=false');
//        allow for set up based on environment variables and using CachedInstrumentation
        putenv('OTEL_PHP_AUTOLOAD_ENABLED=true');
        putenv('OTEL_RESOURCE_ATTRIBUTES=service.version=1.0.0');
        putenv('OTEL_SERVICE_NAME=example-app');
        putenv('OTEL_LOG_LEVEL=warning');
        putenv('OTEL_TRACES_SAMPLER=always_on');
//        putenv('OTEL_TRACES_SAMPLER=traceidratio');
//        putenv('OTEL_TRACES_SAMPLER_ARG=1.00');
        putenv('OTEL_TRACES_EXPORTER=otlp');
        putenv('OTEL_EXPORTER_OTLP_ENDPOINT=http://collector:4317');
//        require installing grpc: https://github.com/open-telemetry/opentelemetry-php#1-install-php-ext-grpc
//        and protobuf https://github.com/open-telemetry/opentelemetry-php#5-install-php-ext-protobuf
//        putenv('OTEL_EXPORTER_OTLP_PROTOCOL=grpc');
        putenv('OTEL_EXPORTER_OTLP_PROTOCOL=grpc');
        putenv('OTEL_PHP_TRACES_PROCESSOR=simple');
//        for setting batch sending
//        putenv('OTEL_BSP_SCHEDULE_DELAY=10000');

        // static
//        $instrumentation = new \OpenTelemetry\API\Common\Instrumentation\CachedInstrumentation('io.opentelemetry.contrib.php');
//        $tracer = $instrumentation->tracer();

        // in memory
//        Create an ArrayObject as the storage for the spans
//        $storage = new \ArrayObject();
//        $exporter = new InMemoryExporter($storage);


        $transport = (new GrpcTransportFactory())->create('http://collector:4317' . OtlpUtil::method(Signals::TRACE));

        $exporter = new SpanExporter($transport);

        $tracerProvider = new TracerProvider(
            new BatchSpanProcessor(
                $exporter,
                ClockFactory::getDefault()
            )
        );
        $tracer = $tracerProvider->getTracer('io.opentelemetry.contrib.php');

        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [Merchant::class, User::class, MerchantSubscriber::class],
            [
                new MerchantSubscriber(),
                TracerInterface::class => $tracer
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::TRACING_PACKAGE])),
            allowGatewaysToBeRegisteredInContainer: true
        );

        $root = $tracer->spanBuilder("root_span")
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();
        $scope = $root->activate();

//        $tracer->spanBuilder('inner')->startSpan()->end();

        $merchantId = '123';
        $this->assertTrue(
            $ecotoneTestSupport
                ->sendCommand(new CreateMerchant($merchantId))
                ->sendQueryWithRouting('user.get', metadata: ['aggregate.id' => $merchantId])
        );

        $root->end();
        $scope->detach();

//        foreach ($storage as $span) {
//            echo PHP_EOL . sprintf(
//                    'TRACE: "%s", SPAN: "%s", PARENT: "%s"',
//                    $span->getTraceId(),
//                    $span->getSpanId(),
//                    $span->getParentSpanId()
//                );
//        }

        $tracerProvider->shutdown();
    }
}