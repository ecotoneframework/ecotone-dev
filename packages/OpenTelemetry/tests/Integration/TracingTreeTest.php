<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Integration;

use ArrayObject;
use Bluestone\Tree\Node;
use Bluestone\Tree\Tree;
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
use Test\Ecotone\OpenTelemetry\Fixture\InMemorySpanExporter;

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
                            'children' => []
                        ]
                    ]
                ]
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
                                            'children' => []
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
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
                                            'children' => []
                                        ]
                                    ]
                                ]
                            ]
                        ],
                        [
                            'details' => ['name' => 'Event Handler: ' . MerchantSubscriberTwo::class . '::merchantToUser'],
                            'children' => [
                                [
                                    'details' => ['name' => 'Command Bus'],
                                    'children' => [
                                        [
                                            'details' => ['name' => 'Command Handler: ' . User::class . '::register'],
                                            'children' => []
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
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
                                                            'children' => []
                                                        ]
                                                    ]
                                                ]
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
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
                                            'children' => []
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ],
            $this->buildTree($exporter)
        );
    }

    private function compareTreesByDetails(
        array $expectedTree,
        array $collectedTree
    ) {
        foreach ($expectedTree as $expectedNode) {
            $this->findAndCompareNode($expectedNode, $collectedTree);
        }
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
            $this->buildTree($exporter)
        );
    }

    public function buildTree(InMemoryExporter $exporter): array
    {
        $tree = [];
        foreach ($exporter->getSpans() as $span) {
            $preparedSpan = [
                'details' => [
                    'name' => $span->getName(),
                    'span_id' => $span->getSpanId(),
                    'parent_id' => $span->getParentContext()->isValid() ? $span->getParentSpanId() : null,
                    'attributes' => $span->getAttributes()->toArray(),
                    'kind' => $span->getKind(),
                    'links' => $span->getLinks()
                ],
                'children' => []
            ];

            $tree = $this->putInTree($preparedSpan, $tree, true);
        }

        return $tree;
    }

    private function putInTree(array $searchedSpan, array $tree, bool $isRoot): array
    {
        $searchedSpanId = $searchedSpan['details']['span_id'];
        $searchedParentId = $searchedSpan['details']['parent_id'];

        foreach ($tree as $nodeId => $node) {
            /** Connect all non parent node */
            if ($node['details']['parent_id'] === $searchedSpanId) {
                unset($tree[$nodeId]);
                $searchedSpan['children'][$nodeId] = $node;
            }
        }
        $changedStructure = $tree;

        foreach ($tree as $nodeId => $node) {
            if ($nodeId === $searchedParentId) {
                if (in_array($searchedSpanId, array_keys($node['children']))) {
                    continue;
                }

                $changedStructure[$nodeId]['children'][$searchedSpanId] = $searchedSpan;
            } elseif ($node['details']['parent_id'] === $searchedSpanId) {
                unset($changedStructure[$nodeId]);
                $searchedSpan['children'][$nodeId] = $node;

                $changedStructure = $this->putInTree(
                    $searchedSpan,
                    $changedStructure,
                    true
                );
            }else {
                // go to children
                $changedStructure[$nodeId]['children'] = $this->putInTree(
                    $searchedSpan,
                    $node['children'],
                    false
                );
            }
        }

        if ($isRoot && $changedStructure == $tree) {
            $changedStructure[$searchedSpanId] = $searchedSpan;
        }

        return $changedStructure;
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

    private function findAndCompareNode(array $expectedTreeNode, array $collectedTree): void
    {
        foreach ($collectedTree as $collectedNode) {
            if ($expectedTreeNode['details']['name'] === $collectedNode['details']['name']) {
                foreach ($expectedTreeNode['details'] as $expectedKey => $expectedValue) {
                    $this->assertArrayHasKey($expectedKey, $collectedNode['details'], "Expected key {$expectedKey} is not present in node {$collectedNode['details']['name']}");
                    $this->assertSame($expectedValue, $collectedNode['details'][$expectedKey], "Expected value for key {$expectedKey} is {$expectedValue}, but got {$collectedNode['details'][$expectedKey]} in node {$collectedNode['details']['name']}");
                }
                $this->compareTreesByDetails($expectedTreeNode['children'], $collectedNode['children']);

                return;
            }
        }

        $this->assertTrue(false, "Could not find node with name {$expectedTreeNode['details']['name']}. Nodes at this level: " . \json_encode($collectedTree));
    }
}
