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
use Ecotone\OpenTelemetry\Support\JaegerTracer;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\SDK\Common\Log\LoggerHolder;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use stdClass;
use Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow\CreateMerchant;
use Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow\Merchant;
use Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow\MerchantCreated;
use Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow\MerchantSubscriberOne;
use Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow\MerchantSubscriberTwo;
use Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow\RegisterUser;
use Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow\User;

/**
 * @internal
 */
final class TracingTreeTest extends TracingTest
{
    public function MANUAL_test_command_event_command_flow()
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

        $tracerProvider = JaegerTracer::create('http://collector:4317');
        $tracer = $tracerProvider->getTracer('io.opentelemetry.contrib.php');

        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [Merchant::class, User::class, MerchantSubscriberOne::class],
            [
                new MerchantSubscriberOne(),
                TracerInterface::class => $tracer,
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::TRACING_PACKAGE]))
                ->withExtensionObjects([
                    TracingConfiguration::createWithDefaults(),
                ]),
            allowGatewaysToBeRegisteredInContainer: true
        );

        $root = $tracer->spanBuilder('root_span')
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();
        $scope = $root->activate();

        $rootInner = $tracer->spanBuilder('root_span_inner')
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();
        $scopeInner = $rootInner->activate();
        //        $tracer->spanBuilder('inner')->startSpan()->end();

        $merchantId = '123';
        //        try {
        //            $ecotoneTestSupport
        //                ->sendCommand(new CreateMerchant($merchantId));
        //        }catch (\Exception) {
        //
        //        }
        $this->assertTrue(
            $ecotoneTestSupport
                ->sendCommand(new CreateMerchant($merchantId))
                ->sendQueryWithRouting('user.get', metadata: ['aggregate.id' => $merchantId])
        );

        $rootInner->end();
        $scopeInner->detach();
        //        $tracerProvider->forceFlush();

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

    public function test_tracing_tree_with_single_levels_of_nesting()
    {
        $exporter = new InMemoryExporter(new ArrayObject());

        EcotoneLite::bootstrapFlowTesting(
            [User::class],
            [TracerInterface::class => $this->prepareTracer($exporter)],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::TRACING_PACKAGE]))
        )
            ->sendCommand(new RegisterUser('1'));

        $this->compareTreesByDetails(
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
            $this->buildTree($exporter)
        );
    }

    public function test_tracing_with_two_levels_of_nesting()
    {
        $exporter = new InMemoryExporter(new ArrayObject());

        EcotoneLite::bootstrapFlowTesting(
            [User::class, MerchantSubscriberOne::class],
            [TracerInterface::class => $this->prepareTracer($exporter), new MerchantSubscriberOne()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::TRACING_PACKAGE]))
        )
            ->publishEvent(new MerchantCreated('1'));

        $this->compareTreesByDetails(
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
            $this->buildTree($exporter)
        );
    }

    public function test_tracing_with_two_levels_of_nesting_and_two_branches_on_same_level()
    {
        $exporter = new InMemoryExporter(new ArrayObject());

        EcotoneLite::bootstrapFlowTesting(
            [User::class, MerchantSubscriberOne::class, MerchantSubscriberTwo::class],
            [TracerInterface::class => $this->prepareTracer($exporter), new MerchantSubscriberOne(), new MerchantSubscriberTwo()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::TRACING_PACKAGE]))
        )
            ->publishEvent(new MerchantCreated('1'));

        $this->compareTreesByDetails(
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
            $this->buildTree($exporter)
        );
    }

    public function test_tracing_with_three_levels_of_nesting()
    {
        $exporter = new InMemoryExporter(new ArrayObject());

        EcotoneLite::bootstrapFlowTesting(
            [Merchant::class, User::class, MerchantSubscriberOne::class],
            [TracerInterface::class => $this->prepareTracer($exporter), new MerchantSubscriberOne()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::TRACING_PACKAGE]))
        )
            ->sendCommand(new CreateMerchant('1'));

        $this->compareTreesByDetails(
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
            $this->buildTree($exporter)
        );
    }

    public function test_tracing_with_asynchronous_handler()
    {
        $exporter = new InMemoryExporter();

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [\Test\Ecotone\OpenTelemetry\Fixture\AsynchronousFlow\User::class],
            [TracerInterface::class => $this->prepareTracer($exporter)],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::TRACING_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    SimpleMessageChannelBuilder::createQueueChannel('async_channel'),
                ])
        )
            ->sendCommand(new RegisterUser('1'));

        $ecotoneLite->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());

        $this->compareTreesByDetails(
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
            $this->buildTree($exporter)
        );
    }

    public function test_passing_user_specific_headers()
    {
        $exporter = new InMemoryExporter();

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [\Test\Ecotone\OpenTelemetry\Fixture\AsynchronousFlow\User::class],
            [TracerInterface::class => $this->prepareTracer($exporter)],
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

        $this->compareTreesByDetails(
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
            $this->buildTree($exporter)
        );
    }

    public function test_user_land_metadata_should_be_skipped_in_case_is_not_scalar(): void
    {
        $exporter = new InMemoryExporter(new ArrayObject());

        EcotoneLite::bootstrapFlowTesting(
            [\Test\Ecotone\OpenTelemetry\Fixture\AsynchronousFlow\User::class],
            [TracerInterface::class => $this->prepareTracer($exporter)],
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
            $this->buildTree($exporter)
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

    public function test_passing_span_context_when_using_distributed_bus(): void
    {

    }
}
