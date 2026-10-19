<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Integration;

use ArrayObject;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\OpenTelemetry\OpenTelemetryConfiguration;
use Ecotone\OpenTelemetry\Support\OTelTracer;
use InvalidArgumentException;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\Common\Log\LoggerHolder;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Test\Ecotone\OpenTelemetry\Fixture\AsynchronousFlow\UserNotifier;
use Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow\RegisterUser;
use Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow\User;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class TracingTreeTest extends TracingTestCase
{
    public function test_disabling_force_flushing_traces()
    {
        $exporter = new InMemoryExporter(new ArrayObject());

        EcotoneLite::bootstrapFlowTesting(
            [User::class],
            [TracerProviderInterface::class => new TracerProvider(
                new BatchSpanProcessor(
                    $exporter,
                    ClockFactory::getDefault()
                )
            )],
            extensionObjects: [OpenTelemetryConfiguration::createWithDefaults()->withForceFlushOnBusExecution(false)->withForceFlushOnAsynchronousMessageHandled(false)],
            enabledModules: [ModulePackageList::TRACING_PACKAGE],
        )
            ->sendCommand(new RegisterUser('1'));

        $this->assertEquals(
            [],
            self::buildTree($exporter)
        );
    }

    public function test_force_flushing_traces()
    {
        $exporter = new InMemoryExporter(new ArrayObject());

        EcotoneLite::bootstrapFlowTesting(
            [User::class],
            [TracerProviderInterface::class => new TracerProvider(
                new BatchSpanProcessor(
                    $exporter,
                    ClockFactory::getDefault()
                )
            )],
            extensionObjects: [OpenTelemetryConfiguration::createWithDefaults()->withForceFlushOnBusExecution(true)->withForceFlushOnAsynchronousMessageHandled(true)],
            enabledModules: [ModulePackageList::TRACING_PACKAGE],
        )
            ->sendCommand(new RegisterUser('1'));

        self::compareTreesByDetails(
            [
                [
                    'details' => ['name' => 'send command'],
                    'children' => [
                        [
                            'details' => ['name' => 'process User::register'],
                            'children' => [],
                        ],
                    ],
                ],
            ],
            self::buildTree($exporter)
        );
    }

    public function test_traces_are_force_flushed_when_synchronous_exception_happens()
    {
        $exporter = new InMemoryExporter(new ArrayObject());

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [User::class],
            [TracerProviderInterface::class => new TracerProvider(
                new BatchSpanProcessor(
                    $exporter,
                    ClockFactory::getDefault()
                )
            )],
            extensionObjects: [OpenTelemetryConfiguration::createWithDefaults()->withForceFlushOnBusExecution(true)->withForceFlushOnAsynchronousMessageHandled(true)],
            enabledModules: [ModulePackageList::TRACING_PACKAGE],
        );

        $exceptionThrown = false;
        try {
            $ecotoneLite->sendCommand(
                new RegisterUser('1'),
                [
                    'throwException' => true,
                ]
            );
        } catch (InvalidArgumentException) {
            $exceptionThrown = true;
        }
        $this->assertTrue($exceptionThrown);

        $node = $this->getNodeAtTargetedSpan(
            [
                'details' => ['name' => 'send command'],
                'child' => [
                    'details' => ['name' => 'process User::register'],
                ],
            ],
            self::buildTree($exporter)
        );

        $this->assertContains('exception', array_map(fn ($event): string => $event->getName(), $node['details']['events']));
    }

    public function MANUAL_test_jaeger_command_event_command_flow()
    {
        //        LoggerHolder::set(new Logger('otlp-example', [new StreamHandler('php://stderr')]));
        putenv('OTEL_SDK_DISABLED=false');
        putenv('OTEL_RESOURCE_ATTRIBUTES=service.version=1.0.0');
        putenv('OTEL_SERVICE_NAME=example-app');
        putenv('OTEL_LOG_LEVEL=warning');
        //        allow for set up based on environment variables and using CachedInstrumentation
        //        putenv('OTEL_PHP_AUTOLOAD_ENABLED=true');
        //        putenv('OTEL_TRACES_SAMPLER=always_on');
        //        putenv('OTEL_TRACES_SAMPLER=traceidratio');
        //        putenv('OTEL_TRACES_SAMPLER_ARG=1.00');
        //        putenv('OTEL_TRACES_EXPORTER=otlp');
        //        putenv('OTEL_EXPORTER_OTLP_ENDPOINT=http://collector:4317');
        //        require installing grpc: https://github.com/open-telemetry/opentelemetry-php#1-install-php-ext-grpc
        //        and protobuf https://github.com/open-telemetry/opentelemetry-php#5-install-php-ext-protobuf
        //        putenv('OTEL_EXPORTER_OTLP_PROTOCOL=grpc');
        //        putenv('OTEL_EXPORTER_OTLP_PROTOCOL=grpc');
        //        putenv('OTEL_PHP_TRACES_PROCESSOR=simple');
        //        for setting batch sending
        //        putenv('OTEL_BSP_SCHEDULE_DELAY=10000');

        // static
        //        $instrumentation = new \OpenTelemetry\API\Common\Instrumentation\CachedInstrumentation('io.opentelemetry.contrib.php');
        //        $tracer = $instrumentation->tracer();

        // in memory
        //        Create an ArrayObject as the storage for the spans
        //        $storage = new \ArrayObject();
        //        $exporter = new InMemoryExporter($storage);

        /** Using Collector */
        //        $tracerProvider = JaegerTracer::create('http://collector:4317');
        /** Using Collector from Jaeger */
        $tracerProvider = OTelTracer::create('http://jaeger:4317');

        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [
                \Test\Ecotone\OpenTelemetry\Fixture\AsynchronousFlow\User::class,
                UserNotifier::class,
            ],
            [
                new UserNotifier(),
                TracerProviderInterface::class => $tracerProvider,
            ],
            extensionObjects: [
                OpenTelemetryConfiguration::createWithDefaults(),
                SimpleMessageChannelBuilder::createQueueChannel('async_channel'),
            ],
            enabledModules: [ModulePackageList::TRACING_PACKAGE, ],
            allowGatewaysToBeRegisteredInContainer: true,
        );

        $ecotoneTestSupport->sendCommand(new RegisterUser('2'), ['flowId' => '2']);
        $ecotoneTestSupport->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup(2));

        //        $tracerProvider->shutdown();
    }
}
