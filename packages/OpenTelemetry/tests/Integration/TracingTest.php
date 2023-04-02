<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Integration;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\OpenTelemetry\Configuration\TracingConfiguration;
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
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\TracerProviderFactory;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow\CreateMerchant;
use Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow\Merchant;
use Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow\MerchantCreated;
use Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow\MerchantSubscriber;
use Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow\RegisterUser;
use Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow\User;

final class TracingTest extends TestCase
{
    public function test_command_event_command_flow()
    {
//        LoggerHolder::set(new Logger('otlp-example', [new StreamHandler('php://stderr')]));

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
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::TRACING_PACKAGE]))
                ->withExtensionObjects([
                    TracingConfiguration::createWithDefaults()
                ]),
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

    public function test_tracing_with_single_levels_of_nesting()
    {
        $exporter = new InMemoryExporter(new \ArrayObject());

        $messageId = Uuid::uuid4()->toString();
        $correlationId = Uuid::uuid4()->toString();
        $timestamp = 1680436648;

        EcotoneLite::bootstrapFlowTesting(
            [User::class],
            [TracerInterface::class => $this->prepareTracer($exporter)],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::TRACING_PACKAGE]))
        )
            ->sendCommand(new RegisterUser("1"), metadata: [
                MessageHeaders::MESSAGE_ID => $messageId,
                MessageHeaders::MESSAGE_CORRELATION_ID => $correlationId,
                MessageHeaders::TIMESTAMP => $timestamp
            ]);

        /** @var ImmutableSpan[] $spans */
        $spans = $exporter->getSpans();
        /** Command Bus and Command Handler */
        $this->assertCount(2, $spans);

        $this->assertSpanWith('Command Handler: ' . User::class . '::register', $spans[0], $messageId, $correlationId, $timestamp);
        $this->assertSpanWith('Command Bus', $spans[1], $messageId, $correlationId, $timestamp);
    }

    public function test_tracing_with_two_levels_of_nesting()
    {
        $exporter = new InMemoryExporter(new \ArrayObject());

        $messageId = Uuid::uuid4()->toString();
        $correlationId = Uuid::uuid4()->toString();
        $timestamp = 1680436648;

        EcotoneLite::bootstrapFlowTesting(
            [User::class, MerchantSubscriber::class],
            [TracerInterface::class => $this->prepareTracer($exporter), new MerchantSubscriber()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::TRACING_PACKAGE]))
        )
            ->publishEvent(new MerchantCreated('1'), [
                MessageHeaders::MESSAGE_ID => $messageId,
                MessageHeaders::MESSAGE_CORRELATION_ID => $correlationId,
                MessageHeaders::TIMESTAMP => $timestamp
            ]);

        /** @var ImmutableSpan[] $spans */
        $spans = $exporter->getSpans();
        /** Command Bus and Command Handler */
        $this->assertCount(4, $spans);

        $this->assertChildSpan('Command Handler: ' . User::class . '::register', $spans[0], $messageId, $correlationId);
        $this->assertChildSpan('Command Bus', $spans[1], $messageId, $correlationId);

        $this->assertSpanWith('Event Handler: ' . MerchantSubscriber::class . '::merchantToUser', $spans[2], $messageId, $correlationId, $timestamp);
        $this->assertSpanWith('Event Bus', $spans[3], $messageId, $correlationId, $timestamp);
    }

    public function test_tracing_with_three_levels_of_nesting()
    {
        $exporter = new InMemoryExporter(new \ArrayObject());

        $messageId = Uuid::uuid4()->toString();
        $correlationId = Uuid::uuid4()->toString();
        $timestamp = 1680436648;

        EcotoneLite::bootstrapFlowTesting(
            [Merchant::class, User::class, MerchantSubscriber::class],
            [TracerInterface::class => $this->prepareTracer($exporter), new MerchantSubscriber()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::TRACING_PACKAGE]))
        )
            ->sendCommand(new CreateMerchant('1'), [
                MessageHeaders::MESSAGE_ID => $messageId,
                MessageHeaders::MESSAGE_CORRELATION_ID => $correlationId,
                MessageHeaders::TIMESTAMP => $timestamp
            ]);

        /** @var ImmutableSpan[] $spans */
        $spans = $exporter->getSpans();
        /** Command Bus and Command Handler */
        $this->assertCount(6, $spans);

        $this->assertChildSpan('Command Handler: ' . User::class . '::register', $spans[0], $spans[2]->getAttributes()->get(MessageHeaders::MESSAGE_ID), $correlationId);
        $this->assertChildSpan('Command Bus', $spans[1], $spans[2]->getAttributes()->get(MessageHeaders::MESSAGE_ID), $correlationId);

        $this->assertChildSpan('Event Handler: ' . MerchantSubscriber::class . '::merchantToUser', $spans[2], $messageId, $correlationId);
        $this->assertChildSpan('Event Bus', $spans[3], $messageId, $correlationId);

        $this->assertSpanWith('Command Handler: ' . Merchant::class . '::create', $spans[4], $messageId, $correlationId, $timestamp);
        $this->assertSpanWith('Command Bus', $spans[5], $messageId, $correlationId, $timestamp);
    }

    private function prepareTracer(SpanExporterInterface $exporter): TracerInterface
    {
        $tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                $exporter
            )
        );

        return $tracerProvider->getTracer('io.opentelemetry.contrib.php');
    }

    private function assertSpanWith(string $expectedName, ImmutableSpan $span, string $messageId, string $correlationId, int $timestamp): void
    {
        $this->assertSame(
            $expectedName,
            $span->getName()
        );
        $attributes = $span->getAttributes()->toArray();
        $this->assertSame($messageId, $attributes[MessageHeaders::MESSAGE_ID]);
        $this->assertSame($correlationId, $attributes[MessageHeaders::MESSAGE_CORRELATION_ID]);
        $this->assertSame($timestamp, $attributes[MessageHeaders::TIMESTAMP]);
    }

    private function assertChildSpan(string $expectedName, ImmutableSpan $span, string $messageId, string $correlationId): void
    {
        $this->assertSame(
            $expectedName,
            $span->getName()
        );
        $attributes = $span->getAttributes()->toArray();
        $this->assertNotSame($messageId, $attributes[MessageHeaders::MESSAGE_ID]);
        $this->assertSame($messageId, $attributes[MessageHeaders::PARENT_MESSAGE_ID]);
        $this->assertSame($correlationId, $attributes[MessageHeaders::MESSAGE_CORRELATION_ID]);
    }
}