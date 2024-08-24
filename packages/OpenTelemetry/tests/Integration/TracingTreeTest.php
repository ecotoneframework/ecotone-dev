<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Integration;

use ArrayObject;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\OpenTelemetry\Configuration\TracingConfiguration;
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
use Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow\CreateMerchant;
use Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow\Merchant;
use Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow\MerchantCreated;
use Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow\MerchantSubscriberOne;
use Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow\MerchantSubscriberTwo;
use Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow\RegisterUser;
use Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow\User;
use Test\Ecotone\OpenTelemetry\Fixture\MessageHandlerFlow\ExampleMessageHandler;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class TracingTreeTest extends TracingTest
{
    public function test_tracing_tree_with_single_levels_of_nesting()
    {
        $exporter = new InMemoryExporter(new ArrayObject());

        EcotoneLite::bootstrapFlowTesting(
            [User::class],
            [TracerProviderInterface::class => TracingTest::prepareTracer($exporter)],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::TRACING_PACKAGE]))
        )
            ->sendCommand(new RegisterUser('1'));

        self::compareTreesByDetails(
            [
                [
                    'details' => ['name' => 'Command Bus'],
                    'children' => [
                        [
                            'details' => ['name' => 'Command Handler: ' . User::class . '::register'],
                            'children' => [],
                        ],
                    ],
                ],
            ],
            self::buildTree($exporter)
        );
    }

    public function test_tracing_tree_with_two_levels_of_nesting_and_message_handler()
    {
        $exporter = new InMemoryExporter(new ArrayObject());

        EcotoneLite::bootstrapFlowTesting(
            [ExampleMessageHandler::class],
            [TracerProviderInterface::class => TracingTest::prepareTracer($exporter), ExampleMessageHandler::class => new ExampleMessageHandler()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::TRACING_PACKAGE]))
        )
            ->sendCommandWithRoutingKey('handleCommand');

        self::compareTreesByDetails(
            [
                [
                    'details' => ['name' => 'Command Bus'],
                    'children' => [
                        [
                            'details' => ['name' => 'Command Handler: ' . ExampleMessageHandler::class . '::handleCommand'],
                            'children' => [],
                        ],
                        [
                            'details' => ['name' => 'Message Handler: ' . ExampleMessageHandler::class . '::handle'],
                            'children' => [],
                        ],
                    ],
                ],
            ],
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
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::TRACING_PACKAGE]))
                ->withExtensionObjects(
                    [
                        TracingConfiguration::createWithDefaults()
                            ->withForceFlushOnBusExecution(false)
                            ->withForceFlushOnAsynchronousMessageHandled(false),
                    ]
                )
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
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::TRACING_PACKAGE]))
                ->withExtensionObjects(
                    [
                        TracingConfiguration::createWithDefaults()
                            ->withForceFlushOnBusExecution(true)
                            ->withForceFlushOnAsynchronousMessageHandled(true),
                    ]
                )
        )
            ->sendCommand(new RegisterUser('1'));

        self::compareTreesByDetails(
            [
                [
                    'details' => ['name' => 'Command Bus'],
                    'children' => [
                        [
                            'details' => ['name' => 'Command Handler: ' . User::class . '::register'],
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
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::TRACING_PACKAGE]))
                ->withExtensionObjects(
                    [
                        TracingConfiguration::createWithDefaults()
                            ->withForceFlushOnBusExecution(true)
                            ->withForceFlushOnAsynchronousMessageHandled(true),
                    ]
                )
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
                'details' => ['name' => 'Command Bus'],
                'child' => [
                    'details' => ['name' => 'Command Handler: ' . User::class . '::register'],
                ],
            ],
            self::buildTree($exporter)
        );

        $this->assertSame(
            'exception',
            $node['details']['events'][1]->getName()
        );
    }

    public function test_separate_traces()
    {
        $exporter = new InMemoryExporter(new ArrayObject());

        EcotoneLite::bootstrapFlowTesting(
            [User::class],
            [TracerProviderInterface::class => TracingTest::prepareTracer($exporter)],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::TRACING_PACKAGE]))
        )
            ->sendCommand(new RegisterUser('1'), ['flowId' => '1'])
            ->sendCommand(new RegisterUser('2'), ['flowId' => '2']);

        self::compareTreesByDetails(
            [
                [
                    'details' => ['name' => 'Command Bus', 'attributes' => ['flowId' => '1']],
                    'children' => [
                        [
                            'details' => ['name' => 'Command Handler: ' . User::class . '::register', 'attributes' => ['flowId' => '1']],
                            'children' => [],
                        ],
                    ],
                ],
                [
                    'details' => ['name' => 'Command Bus', 'attributes' => ['flowId' => '2']],
                    'children' => [
                        [
                            'details' => ['name' => 'Command Handler: ' . User::class . '::register', 'attributes' => ['flowId' => '2']],
                            'children' => [],
                        ],
                    ],
                ],
            ],
            self::buildTree($exporter)
        );
    }

    public function test_tracing_with_two_levels_of_nesting()
    {
        $exporter = new InMemoryExporter(new ArrayObject());

        EcotoneLite::bootstrapFlowTesting(
            [User::class, MerchantSubscriberOne::class],
            [TracerProviderInterface::class => TracingTest::prepareTracer($exporter), new MerchantSubscriberOne()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::TRACING_PACKAGE]))
        )
            ->publishEvent(new MerchantCreated('1'));

        self::compareTreesByDetails(
            [
                [
                    'details' => ['name' => 'Event Bus'],
                    'children' => [
                        [
                            'details' => ['name' => 'Event Handler: ' . MerchantSubscriberOne::class . '::merchantToUser'],
                            'children' => [
                                [
                                    'details' => ['name' => 'Command Bus'],
                                    'children' => [
                                        [
                                            'details' => ['name' => 'Command Handler: ' . User::class . '::register'],
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

    public function test_tracing_with_two_levels_of_nesting_and_two_branches_on_same_level()
    {
        $exporter = new InMemoryExporter(new ArrayObject());

        EcotoneLite::bootstrapFlowTesting(
            [User::class, MerchantSubscriberOne::class, MerchantSubscriberTwo::class],
            [TracerProviderInterface::class => TracingTest::prepareTracer($exporter), new MerchantSubscriberOne(), new MerchantSubscriberTwo()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::TRACING_PACKAGE]))
        )
            ->publishEvent(new MerchantCreated('1'));

        self::compareTreesByDetails(
            [
                [
                    'details' => ['name' => 'Event Bus'],
                    'children' => [
                        [
                            'details' => ['name' => 'Event Handler: ' . MerchantSubscriberOne::class . '::merchantToUser'],
                            'children' => [
                                [
                                    'details' => ['name' => 'Command Bus'],
                                    'children' => [
                                        [
                                            'details' => ['name' => 'Command Handler: ' . User::class . '::register'],
                                            'children' => [],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'details' => ['name' => 'Event Handler: ' . MerchantSubscriberTwo::class . '::merchantToUser'],
                            'children' => [
                                [
                                    'details' => ['name' => 'Command Bus'],
                                    'children' => [
                                        [
                                            'details' => ['name' => 'Command Handler: ' . User::class . '::register'],
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

    public function test_tracing_with_three_levels_of_nesting()
    {
        $exporter = new InMemoryExporter(new ArrayObject());

        EcotoneLite::bootstrapFlowTesting(
            [Merchant::class, User::class, MerchantSubscriberOne::class],
            [TracerProviderInterface::class => TracingTest::prepareTracer($exporter), new MerchantSubscriberOne()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::TRACING_PACKAGE]))
        )
            ->sendCommand(new CreateMerchant('1'));

        self::compareTreesByDetails(
            [
                [
                    'details' => ['name' => 'Command Bus'],
                    'children' => [
                        [
                            'details' => ['name' => 'Command Handler: ' . Merchant::class . '::create'],
                            'children' => [
                                [
                                    'details' => ['name' => 'Event Bus'],
                                    'children' => [
                                        [
                                            'details' => ['name' => 'Event Handler: ' . MerchantSubscriberOne::class . '::merchantToUser'],
                                            'children' => [
                                                [
                                                    'details' => ['name' => 'Command Bus'],
                                                    'children' => [
                                                        [
                                                            'details' => ['name' => 'Command Handler: ' . User::class . '::register'],
                                                            'children' => [],
                                                        ],
                                                    ],
                                                ],
                                            ],
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

    public function test_tracing_with_asynchronous_handler()
    {
        $exporter = new InMemoryExporter();

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [\Test\Ecotone\OpenTelemetry\Fixture\AsynchronousFlow\User::class],
            [TracerProviderInterface::class => TracingTest::prepareTracer($exporter)],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::TRACING_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    SimpleMessageChannelBuilder::createQueueChannel('async_channel'),
                ])
        )
            ->sendCommand(new RegisterUser('1'));

        $ecotoneLite->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());

        self::compareTreesByDetails(
            [
                [
                    'details' => ['name' => 'Command Bus'],
                    'children' => [
                        [
                            'details' => ['name' => 'Sending to Channel: async_channel'],
                            'children' => [
                                [
                                    'details' => ['name' => 'Receiving from channel: async_channel'],
                                    'children' => [
                                        [
                                            'details' => ['name' => 'Command Handler: ' . \Test\Ecotone\OpenTelemetry\Fixture\AsynchronousFlow\User::class . '::register'],
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
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::TRACING_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    SimpleMessageChannelBuilder::createQueueChannel('async_channel'),
                ])
        )
            ->sendCommand(new RegisterUser('1'))
            ->run('async_channel');

        $result = self::getNodeAtTargetedSpan(
            [
                'details' => ['name' => 'Command Bus'],
                'child' => [
                    'details' => ['name' => 'Sending to Channel: async_channel'],
                    'child' => [
                        'details' => ['name' => 'Receiving from channel: async_channel'],
                        'child' => [
                            'details' => ['name' => 'Event Bus'],
                        ],
                    ],
                ],
            ],
            self::buildTree($exporter)
        );

        /** @var Event $event */
        $event = $result['details']['events'][0];
        $this->stringStartsWith(
            'Publishing Event Message using Class routing',
        )->evaluate($event->getName());

        /** @var Event $event */
        $event = $result['details']['events'][2];
        $this->stringStartsWith(
            'Collecting message with id',
        )->evaluate($event->getName());
    }

    public function test_two_traces_with_asynchronous_handlers()
    {
        $exporter = new InMemoryExporter();

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [\Test\Ecotone\OpenTelemetry\Fixture\AsynchronousFlow\User::class],
            [TracerProviderInterface::class => TracingTest::prepareTracer($exporter)],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::TRACING_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    SimpleMessageChannelBuilder::createQueueChannel('async_channel'),
                ])
        )
            ->sendCommand(new RegisterUser('1'), ['flowId' => '1'])
            ->sendCommand(new RegisterUser('2'), ['flowId' => '2']);

        $ecotoneLite->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 2));

        $collectedTree = self::buildTree($exporter);
        self::compareTreesByDetails(
            [
                [
                    'details' => ['name' => 'Command Bus', 'attributes' => ['flowId' => '1']],
                    'children' => [
                        [
                            'details' => ['name' => 'Sending to Channel: async_channel', 'attributes' => ['flowId' => '1']],
                            'children' => [
                                [
                                    'details' => ['name' => 'Receiving from channel: async_channel', 'attributes' => ['flowId' => '1']],
                                    'children' => [
                                        [
                                            'details' => ['name' => 'Command Handler: ' . \Test\Ecotone\OpenTelemetry\Fixture\AsynchronousFlow\User::class . '::register', 'attributes' => ['flowId' => '1']],
                                            'children' => [],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'details' => ['name' => 'Command Bus', 'attributes' => ['flowId' => '2']],
                    'children' => [
                        [
                            'details' => ['name' => 'Sending to Channel: async_channel', 'attributes' => ['flowId' => '2']],
                            'children' => [
                                [
                                    'details' => ['name' => 'Receiving from channel: async_channel', 'attributes' => ['flowId' => '2']],
                                    'children' => [
                                        [
                                            'details' => ['name' => 'Command Handler: ' . \Test\Ecotone\OpenTelemetry\Fixture\AsynchronousFlow\User::class . '::register', 'attributes' => ['flowId' => '2']],
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
            [TracerProviderInterface::class => TracingTest::prepareTracer($exporter)],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::TRACING_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    SimpleMessageChannelBuilder::createQueueChannel('async_channel'),
                ])
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
                        'name' => 'Command Bus',
                        'attributes' => ['user_id' => '123'],
                    ],
                    'children' => [
                        [
                            'details' => [
                                'name' => 'Sending to Channel: async_channel',
                                'attributes' => ['user_id' => '123'],
                            ],
                            'children' => [
                                [
                                    'details' => [
                                        'name' => 'Receiving from channel: async_channel',
                                        'attributes' => ['user_id' => '123'],
                                    ],
                                    'children' => [
                                        [
                                            'details' => [
                                                'name' => 'Command Handler: ' . \Test\Ecotone\OpenTelemetry\Fixture\AsynchronousFlow\User::class . '::register',
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
            [TracerProviderInterface::class => TracingTest::prepareTracer($exporter)],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::TRACING_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    SimpleMessageChannelBuilder::createQueueChannel('async_channel'),
                ])
        )
            ->sendCommand(new RegisterUser('1'), [
                'tokens' => ['123'],
                'user' => new stdClass(),
            ])
            ->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());

        $node = $this->getNodeAtTargetedSpan(
            [
                'details' => ['name' => 'Command Bus'],
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
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([
                    ModulePackageList::TRACING_PACKAGE,
                    ModulePackageList::ASYNCHRONOUS_PACKAGE,
                ]))
                ->withExtensionObjects([
                    TracingConfiguration::createWithDefaults(),
                    SimpleMessageChannelBuilder::createQueueChannel('async_channel'),
                ]),
            allowGatewaysToBeRegisteredInContainer: true
        );

        $ecotoneTestSupport->sendCommand(new RegisterUser('2'), ['flowId' => '2']);
        $ecotoneTestSupport->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup(2));

        //        $tracerProvider->shutdown();
    }
}
