<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Integration;

use ArrayObject;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\OpenTelemetry\Configuration\TracingConfiguration;
use Ecotone\OpenTelemetry\Support\JaegerTracer;
use InvalidArgumentException;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\SDK\Common\Log\LoggerHolder;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use Ramsey\Uuid\Uuid;
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

        $this->assertSame(
            [
                'Command Bus' => [
                    'Command Handler: ' . User::class . '::register' => [],
                ],
            ],
            $this->buildTree($exporter->getSpans())
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

        $this->assertSame(
            [
                'Event Bus' => [
                    'Event Handler: ' . MerchantSubscriberOne::class . '::merchantToUser' => [
                        'Command Bus' => [
                            'Command Handler: ' . User::class . '::register' => [],
                        ],
                    ],
                ],
            ],
            $this->buildTree($exporter->getSpans())
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

        $this->assertSame(
            [
                'Event Bus' => [
                    'Event Handler: ' . MerchantSubscriberTwo::class . '::merchantToUser' => [
                        'Command Bus' => [
                            'Command Handler: ' . User::class . '::register' => [],
                        ],
                    ],
                    'Event Handler: ' . MerchantSubscriberOne::class . '::merchantToUser' => [
                        'Command Bus' => [
                            'Command Handler: ' . User::class . '::register' => [],
                        ],
                    ],
                ],
            ],
            $this->buildTree($exporter->getSpans())
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

        $this->assertSame(
            [
                'Command Bus' => [
                    'Command Handler: ' . Merchant::class . '::create' => [
                        'Event Bus' => [
                            'Event Handler: ' . MerchantSubscriberOne::class . '::merchantToUser' => [
                                'Command Bus' => [
                                    'Command Handler: ' . User::class . '::register' => [],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            $this->buildTree($exporter->getSpans())
        );
    }

    public function TODO_test_tracing_with_asynchronous_handler()
    {
        $storage = new ArrayObject();
        $exporter = new InMemoryExporter($storage);

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

        $this->assertSame(
            [
                'Command Bus' => [
                    'Asynchronous Channel: async_channel' => [],
                ],
            ],
            $this->buildTree($exporter->getSpans())
        );

        foreach ($storage as $key => $value) {
            unset($storage[$key]);
        }
        /** @TODO close the tracer before starting async consumer */

        $ecotoneLite->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());


        $this->assertSame(
            [
                'Command Bus' => [
                    'Command Handler: ' . \Test\Ecotone\OpenTelemetry\Fixture\AsynchronousFlow\User::class . '::register' => [],
                ],
            ],
            $this->buildTree($exporter->getSpans())
        );
    }

    public function TODO_test_tracing_with_exception()
    {
        $exporter = new InMemoryExporter(new ArrayObject());

        $messageId = Uuid::uuid4()->toString();
        $correlationId = Uuid::uuid4()->toString();
        $timestamp = 1680436648;

        try {
            EcotoneLite::bootstrapFlowTesting(
                [\Test\Ecotone\OpenTelemetry\Fixture\ExceptionFlow\User::class],
                [TracerInterface::class => $this->prepareTracer($exporter)],
                ServiceConfiguration::createWithDefaults()
                    ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::TRACING_PACKAGE]))
            )
                ->sendCommand(new RegisterUser('1'), metadata: [
                    MessageHeaders::MESSAGE_ID => $messageId,
                    MessageHeaders::MESSAGE_CORRELATION_ID => $correlationId,
                    MessageHeaders::TIMESTAMP => $timestamp,
                ]);
        } catch (InvalidArgumentException) {
        }

        $this->assertSame(
            [
                'Command Bus' => [
                    'Command Handler: ' . \Test\Ecotone\OpenTelemetry\Fixture\ExceptionFlow\User::class . '::register' => [],
                ],
            ],
            $this->buildTree($exporter->getSpans())
        );
    }

    /**
     * @param ImmutableSpan[] $spans
     */
    public function buildTree(array $spans): array
    {
        $tree = new ArrayObject();
        $spanIdNameMapping = [];

        foreach (array_reverse($spans) as $span) {
            $spanId = $span->getSpanId();
            $spanName = $span->getName();
            $spanParent = $span->getParentSpanId();
            $spanIdNameMapping[$spanId] = $spanName;

            if (! $span->getParentContext()->isValid()) {
                $tree[$spanId] = new ArrayObject();
            } else {
                $parentTree = $this->getSpanParentTree($spanParent, $tree);
                $this->assertNotNull($parentTree, "Parent tree not found for span: $spanId");

                $parentTree[$spanId] = new ArrayObject();
            }
        }

        return $this->rebuildTreeWithNames($tree, $spanIdNameMapping);
    }

    private function getSpanParentTree(string $spanIdToFind, ArrayObject $treeOnGivenLevel): ?ArrayObject
    {
        foreach ($treeOnGivenLevel as $spanId => $children) {
            if ($spanIdToFind === $spanId) {
                return $children;
            } else {
                $innerTree = $this->getSpanParentTree($spanIdToFind, $children);

                if ($innerTree !== null) {
                    return $innerTree;
                }
            }
        }

        return null;
    }

    private function rebuildTreeWithNames(ArrayObject $tree, array $spanIdNameMapping): array
    {
        $treeWithNames = [];

        foreach ($tree as $spanId => $children) {
            $treeWithNames[$spanIdNameMapping[$spanId]] = $this->rebuildTreeWithNames($children, $spanIdNameMapping);
        }

        return $treeWithNames;
    }

    /** @var string[] */
    private array $parentReferences = [];

    public function followParents(ImmutableSpan $span, array $parentReferences): array
    {
        $spanName = $span->getName();

        if (! $span->getParentContext()->isValid()) {
            return [$spanName => []];
        }


    }
}
