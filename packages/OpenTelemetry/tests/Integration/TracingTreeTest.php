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
use OpenTelemetry\SDK\Trace\Event;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use stdClass;
use Test\Ecotone\OpenTelemetry\Fixture\AsynchronousFlow\UserNotifier;
use Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow\RegisterUser;
use Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow\User;
use Test\Ecotone\OpenTelemetry\Fixture\ScheduledHandler\ScheduledHandler;
use Test\Ecotone\OpenTelemetry\Fixture\ScheduledHandler\WorkflowScheduledHandler;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class TracingTreeTest extends TracingTestCase
{
    public function test_tracing_scheduled_handler()
    {
        $exporter = new InMemoryExporter(new ArrayObject());

        EcotoneLite::bootstrapFlowTesting(
            [ScheduledHandler::class],
            [TracerProviderInterface::class => TracingTestCase::prepareTracer($exporter), ScheduledHandler::class => new ScheduledHandler()],
            enabledModules: [ModulePackageList::TRACING_PACKAGE],
        )
            ->run('scheduled_handler', ExecutionPollingMetadata::createWithDefaults()->withExecutionAmountLimit(2));

        self::compareTreesByDetails(
            [
                [
                    'details' => ['name' => 'receive nullChannel'],
                    'children' => [],
                ],
                [
                    'details' => ['name' => 'receive nullChannel'],
                    'children' => [],
                ],
            ],
            self::buildTree($exporter)
        );
    }

    public function test_tracing_workflow_scheduled_handler()
    {
        $exporter = new InMemoryExporter(new ArrayObject());

        EcotoneLite::bootstrapFlowTesting(
            [WorkflowScheduledHandler::class],
            [TracerProviderInterface::class => TracingTestCase::prepareTracer($exporter), WorkflowScheduledHandler::class => new WorkflowScheduledHandler()],
            enabledModules: [ModulePackageList::TRACING_PACKAGE],
        )
            ->run('scheduled_handler', ExecutionPollingMetadata::createWithDefaults()->withExecutionAmountLimit(2));

        self::compareTreesByDetails(
            [
                [
                    'details' => ['name' => 'receive add'],
                    'children' => [
                        [
                            'details' => ['name' => 'process WorkflowScheduledHandler::add'],
                            'children' => [],
                        ],
                    ],
                ],
                [
                    'details' => ['name' => 'receive add'],
                    'children' => [
                        [
                            'details' => ['name' => 'process WorkflowScheduledHandler::add'],
                            'children' => [],
                        ],
                    ],
                ],
            ],
            self::buildTree($exporter)
        );
    }

    public function test_no_tracing_when_there_is_nothing_to_trace()
    {
        $exporter = new InMemoryExporter(new ArrayObject());

        EcotoneLite::bootstrapFlowTesting(
            [WorkflowScheduledHandler::class],
            [TracerProviderInterface::class => TracingTestCase::prepareTracer($exporter), WorkflowScheduledHandler::class => new WorkflowScheduledHandler()],
            enabledModules: [ModulePackageList::TRACING_PACKAGE],
        )
            ->run('scheduled_handler_without_result', ExecutionPollingMetadata::createWithDefaults()->withExecutionAmountLimit(1));

        $this->assertEquals(
            [],
            self::buildTree($exporter)
        );
    }

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

    public function test_separate_traces()
    {
        $exporter = new InMemoryExporter(new ArrayObject());

        EcotoneLite::bootstrapFlowTesting(
            [User::class],
            [TracerProviderInterface::class => TracingTestCase::prepareTracer($exporter)],
            enabledModules: [ModulePackageList::TRACING_PACKAGE],
        )
            ->sendCommand(new RegisterUser('1'), ['flowId' => '1'])
            ->sendCommand(new RegisterUser('2'), ['flowId' => '2']);

        self::compareTreesByDetails(
            [
                [
                    'details' => ['name' => 'send command', 'attributes' => ['flowId' => '1']],
                    'children' => [
                        [
                            'details' => ['name' => 'process User::register', 'attributes' => ['flowId' => '1']],
                            'children' => [],
                        ],
                    ],
                ],
                [
                    'details' => ['name' => 'send command', 'attributes' => ['flowId' => '2']],
                    'children' => [
                        [
                            'details' => ['name' => 'process User::register', 'attributes' => ['flowId' => '2']],
                            'children' => [],
                        ],
                    ],
                ],
            ],
            self::buildTree($exporter)
        );
    }

    public function test_adding_logs_as_events_in_span()
    {
        $exporter = new InMemoryExporter();

        EcotoneLite::bootstrapFlowTesting(
            [
                \Test\Ecotone\OpenTelemetry\Fixture\AsynchronousFlow\User::class,
                UserNotifier::class,
            ],
            [
                new UserNotifier(),
                TracerProviderInterface::class => self::prepareTracer($exporter),
            ],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('async_channel')],
            enabledModules: [ModulePackageList::TRACING_PACKAGE, ],
        )
            ->sendCommand(new RegisterUser('1'))
            ->run('async_channel');

        $result = self::getNodeAtTargetedSpan(
            [
                'details' => ['name' => 'send command'],
                'child' => [
                    'details' => ['name' => 'send async_channel'],
                    'child' => [
                        'details' => ['name' => 'receive async_channel'],
                        'child' => [
                            'details' => ['name' => 'send event'],
                        ],
                    ],
                ],
            ],
            self::buildTree($exporter)
        );

        $eventBusEvents = array_map(fn (Event $event): string => $event->getName(), $result['details']['events']);
        $this->assertNotEmpty(array_filter($eventBusEvents, fn (string $name): bool => str_starts_with($name, 'Publishing Event Message')));

        $route = $result['children'][array_key_first($result['children'])];
        $this->assertSame('route user.registered', $route['details']['name']);
        $routeEvents = array_map(fn (Event $event): string => $event->getName(), $route['details']['events']);
        $this->assertNotEmpty(array_filter($routeEvents, fn (string $name): bool => str_starts_with($name, 'Collecting message with id')));
    }

    public function test_two_traces_with_asynchronous_handlers()
    {
        $exporter = new InMemoryExporter();

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [\Test\Ecotone\OpenTelemetry\Fixture\AsynchronousFlow\User::class],
            [TracerProviderInterface::class => TracingTestCase::prepareTracer($exporter)],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('async_channel')],
            enabledModules: [ModulePackageList::TRACING_PACKAGE, ],
        )
            ->sendCommand(new RegisterUser('1'), ['flowId' => '1'])
            ->sendCommand(new RegisterUser('2'), ['flowId' => '2']);

        $ecotoneLite->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 2));

        $collectedTree = self::buildTree($exporter);
        self::compareTreesByDetails(
            [
                [
                    'details' => ['name' => 'send command', 'attributes' => ['flowId' => '1']],
                    'children' => [
                        [
                            'details' => ['name' => 'send async_channel', 'attributes' => ['flowId' => '1']],
                            'children' => [
                                [
                                    'details' => ['name' => 'receive async_channel', 'attributes' => ['flowId' => '1']],
                                    'children' => [
                                        [
                                            'details' => ['name' => 'process User::register', 'attributes' => ['flowId' => '1']],
                                            'children' => [],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'details' => ['name' => 'send command', 'attributes' => ['flowId' => '2']],
                    'children' => [
                        [
                            'details' => ['name' => 'send async_channel', 'attributes' => ['flowId' => '2']],
                            'children' => [
                                [
                                    'details' => ['name' => 'receive async_channel', 'attributes' => ['flowId' => '2']],
                                    'children' => [
                                        [
                                            'details' => ['name' => 'process User::register', 'attributes' => ['flowId' => '2']],
                                            'children' => [],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            $collectedTree
        );
    }

    public function test_passing_user_specific_headers()
    {
        $exporter = new InMemoryExporter();

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [\Test\Ecotone\OpenTelemetry\Fixture\AsynchronousFlow\User::class],
            [TracerProviderInterface::class => TracingTestCase::prepareTracer($exporter)],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('async_channel')],
            enabledModules: [ModulePackageList::TRACING_PACKAGE, ],
        )
            ->sendCommand(
                new RegisterUser('1'),
                [
                    'user_id' => '123',
                ]
            );

        $ecotoneLite->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());

        self::compareTreesByDetails(
            [
                [
                    'details' => [
                        'name' => 'send command',
                        'attributes' => ['user_id' => '123'],
                    ],
                    'children' => [
                        [
                            'details' => [
                                'name' => 'send async_channel',
                                'attributes' => ['user_id' => '123'],
                            ],
                            'children' => [
                                [
                                    'details' => [
                                        'name' => 'receive async_channel',
                                        'attributes' => ['user_id' => '123'],
                                    ],
                                    'children' => [
                                        [
                                            'details' => [
                                                'name' => 'process User::register',
                                                'attributes' => ['user_id' => '123'],
                                            ],
                                            'children' => [],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            self::buildTree($exporter)
        );
    }

    public function test_user_land_metadata_should_be_skipped_in_case_is_not_scalar(): void
    {
        $exporter = new InMemoryExporter(new ArrayObject());

        EcotoneLite::bootstrapFlowTesting(
            [\Test\Ecotone\OpenTelemetry\Fixture\AsynchronousFlow\User::class],
            [TracerProviderInterface::class => TracingTestCase::prepareTracer($exporter)],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('async_channel')],
            enabledModules: [ModulePackageList::TRACING_PACKAGE, ],
        )
            ->sendCommand(new RegisterUser('1'), [
                'tokens' => ['123'],
                'user' => new stdClass(),
            ])
            ->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());

        $node = $this->getNodeAtTargetedSpan(
            [
                'details' => ['name' => 'send command'],
            ],
            self::buildTree($exporter)
        );

        $this->assertArrayNotHasKey(
            'user',
            $node['details']['attributes']
        );
        $this->assertArrayNotHasKey(
            'tokens',
            $node['details']['attributes']
        );
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
