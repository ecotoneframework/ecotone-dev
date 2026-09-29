<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel\Config;

use function array_diff;
use function array_keys;
use function array_map;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\DecisionBoundary;
use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventSourcingSaga;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\Api\Attribute\Saga;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\EventSourcing\EventStore;
use Ecotone\EventSourcing\Mapping\EventMapper;
use Ecotone\EventSourcing\Tagging\Config\DynamicConsistencyBoundary;
use Ecotone\EventSourcing\Tagging\DynamicConsistencyBoundaryDisabled;
use Ecotone\EventSourcing\Tagging\EventTagRegistry;
use Ecotone\EventSourcing\Tagging\EventTagRegistryBuilder;
use Ecotone\EventSourcing\Tagging\TagResolver;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInterceptorBuilder;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\MessageConverter\DefaultHeaderMapper;
use Ecotone\Messaging\Precedence;
use Ecotone\Modelling\AggregateFlow\SaveAggregate\AggregateResolver\AggregateDefinitionRegistry;
use Ecotone\Modelling\DecisionModel\AggregateBackedDecisionModelDefinition;
use Ecotone\Modelling\DecisionModel\CrossConnectionDecisionModelGuard;
use Ecotone\Modelling\DecisionModel\DecisionModelAppendInterceptor;
use Ecotone\Modelling\DecisionModel\DecisionModelBatchLoader;
use Ecotone\Modelling\DecisionModel\DecisionModelDefinition;
use Ecotone\Modelling\DecisionModel\DecisionModelDefinitionBuilder;
use Ecotone\Modelling\DecisionModel\DecisionModelDefinitionRegistry;
use Ecotone\Modelling\DecisionModel\DecisionModelExecutorRegistry;
use Ecotone\Modelling\DecisionModel\DecisionModelHandler;
use Ecotone\Modelling\DecisionModel\DecisionModelHandlers;
use Ecotone\Modelling\DecisionModel\DecisionModelTagResolvabilityGuard;
use Ecotone\Modelling\DecisionModel\Snapshot\DecisionModelSnapshotStore;
use Ecotone\Modelling\EventSourcingExecutor\EventSourcingHandlerExecutorBuilder;

use function implode;

use Psr\Container\ContainerInterface;
use ReflectionClass;

use function sprintf;

#[ModuleAnnotation]
/**
 * licence Enterprise
 */
final class DecisionModelModule extends NoExternalConfigurationModule implements AnnotationModule
{
    private const INTERCEPTOR_REFERENCE_NAME = 'decisionModel.appendInterceptor';
    private const BATCH_LOADER_REFERENCE_PREFIX = 'decisionModel.batchLoader.';

    /**
     * @param class-string[] $decisionModelClasses
     * @param array<class-string, array{tagNames: string[], handledEventClasses: class-string[], aggregate?: array{className: class-string, aggregateType: string, streamName: string, identifierNames: string[]}}> $rawDefinitions
     */
    private function __construct(
        private readonly AnnotationFinder $annotationFinder,
        private readonly array $decisionModelClasses,
        private readonly array $rawDefinitions,
        private readonly DecisionModelHandlers $handlers,
    ) {
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        $decisionModelClasses = $annotationRegistrationService->findAnnotatedClasses(DecisionModel::class);
        $eventTagRegistry = EventTagRegistry::createWith(EventTagRegistryBuilder::buildRawDefinitions($annotationRegistrationService));

        $rawDefinitions = [];
        foreach ($decisionModelClasses as $decisionModelClass) {
            $classDefinition = $interfaceToCallRegistry->getClassDefinitionFor(Type::create($decisionModelClass));
            $declaration = $classDefinition->findSingleClassAnnotation(Type::create(DecisionModel::class));

            $rawDefinitions[$decisionModelClass] = self::rawDefinitionOf(DecisionModelDefinitionBuilder::buildFor(
                $classDefinition,
                $interfaceToCallRegistry,
                $eventTagRegistry,
                $declaration?->tags ?? [],
                $declaration?->aggregate,
            ));
        }

        return new self(
            $annotationRegistrationService,
            $decisionModelClasses,
            $rawDefinitions,
            DecisionModelHandlers::scan($annotationRegistrationService, $interfaceToCallRegistry),
        );
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        if (! DynamicConsistencyBoundary::resolveFrom($extensionObjects)->isEnabled() && $this->usesDecisionModels()) {
            throw DynamicConsistencyBoundaryDisabled::exception();
        }

        self::assertEverySnapshottedClassIsADecisionModel(
            DynamicConsistencyBoundary::resolveFrom($extensionObjects)->snapshottedModelClasses(),
            $this->rawDefinitions,
        );

        if ($this->decisionModelClasses !== []) {
            self::assertNoModelScopedOnlyByFilterOnlyTags($this->rawDefinitions, DynamicConsistencyBoundary::resolveFrom($extensionObjects)->filterOnlyTagNames());
            CrossConnectionDecisionModelGuard::assertNoCrossConnectionInjection(
                $this->annotationFinder,
                $interfaceToCallRegistry,
                $extensionObjects,
                $this->rawDefinitions,
            );
            self::assertNoAmbiguousDuplicateModelInjection($this->handlers);
            DecisionModelTagResolvabilityGuard::assertEveryModelTagResolvableFromItsMessage($this->annotationFinder, $interfaceToCallRegistry, $this->rawDefinitions);
        }

        $messagingConfiguration->registerServiceDefinition(
            DecisionModelSnapshotStore::class,
            new Definition(DecisionModelSnapshotStore::class, [
                DynamicConsistencyBoundary::resolveFrom($extensionObjects)->snapshottedModelClasses(),
                Reference::to(ContainerInterface::class),
                Reference::to(ConversionService::REFERENCE_NAME),
                Reference::to(LoggingGateway::class),
            ]),
        );

        $messagingConfiguration->registerServiceDefinition(
            DecisionModelDefinitionRegistry::class,
            new Definition(DecisionModelDefinitionRegistry::class, [$this->rawDefinitions], 'createWith'),
        );

        foreach (array_keys($this->rawDefinitions) as $modelClass) {
            $classDefinition = $interfaceToCallRegistry->getClassDefinitionFor(Type::create($modelClass));

            $messagingConfiguration->registerServiceDefinition(
                DecisionModelExecutorRegistry::serviceIdFor($modelClass),
                EventSourcingHandlerExecutorBuilder::createFor($classDefinition, $interfaceToCallRegistry),
            );
        }

        self::assertNoSagaFetchedIntoADecision($this->handlers);

        foreach ($this->handlers->loadingBeforeInvocation() as $handler) {
            $batchLoaderReference = self::BATCH_LOADER_REFERENCE_PREFIX . $handler->key();

            $messagingConfiguration->registerServiceDefinition(
                $batchLoaderReference,
                new Definition(DecisionModelBatchLoader::class, [
                    Reference::to(EventStore::RAW_REFERENCE),
                    Reference::to(TagResolver::class),
                    $handler->modelLoaderDefinitions(),
                    $handler->aggregateBackedModelLoaderDefinitions(),
                    $handler->fetchedAggregateCaptureDefinitions(),
                    $handler->decisionBoundaryDefinitions(),
                    Reference::to(DecisionModelSnapshotStore::class),
                ]),
            );

            $messagingConfiguration->registerBeforeMethodInterceptor(
                MethodInterceptorBuilder::create(
                    Reference::to($batchLoaderReference),
                    $interfaceToCallRegistry->getFor(DecisionModelBatchLoader::class, 'load'),
                    Precedence::SYSTEM_PRECEDENCE_AFTER,
                    $handler->key(),
                    true,
                )
            );
        }

        $handlersAppendingTheirResult = $this->handlers->appendingTheirResult();
        if ($handlersAppendingTheirResult === []) {
            return;
        }

        $pointcut = implode(' || ', array_map(
            static fn (DecisionModelHandler $handler): string => $handler->key(),
            $handlersAppendingTheirResult
        ));

        $messagingConfiguration->registerServiceDefinition(
            self::INTERCEPTOR_REFERENCE_NAME,
            new Definition(DecisionModelAppendInterceptor::class, [
                Reference::to(EventStore::RAW_REFERENCE),
                Reference::to(ConversionService::REFERENCE_NAME),
                DefaultHeaderMapper::createAllHeadersMapping()->getDefinition(),
                Reference::to(EventMapper::class),
                Reference::to(EcotoneClockInterface::class),
                Reference::to(EventBus::class),
                Reference::to(AggregateDefinitionRegistry::class),
                Reference::to(DecisionModelSnapshotStore::class),
            ]),
        );

        $messagingConfiguration->registerAroundMethodInterceptor(
            AroundInterceptorBuilder::create(
                self::INTERCEPTOR_REFERENCE_NAME,
                $interfaceToCallRegistry->getFor(DecisionModelAppendInterceptor::class, 'append'),
                Precedence::DEFAULT_PRECEDENCE,
                $pointcut,
            )
        );
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::CORE_PACKAGE;
    }

    /**
     * @return array{tagNames: string[], handledEventClasses: class-string[], aggregate?: array{className: class-string, aggregateType: string, streamName: string, identifierNames: string[]}}
     */
    private static function rawDefinitionOf(DecisionModelDefinition|AggregateBackedDecisionModelDefinition $definition): array
    {
        if ($definition instanceof DecisionModelDefinition) {
            return [
                'tagNames' => $definition->tagNames(),
                'handledEventClasses' => $definition->handledEventClasses(),
            ];
        }

        return [
            'tagNames' => [],
            'handledEventClasses' => $definition->handledEventClasses(),
            'aggregate' => [
                'className' => $definition->aggregateClassName(),
                'aggregateType' => $definition->aggregateType(),
                'streamName' => $definition->streamName(),
                'identifierNames' => $definition->identifierNames(),
            ],
        ];
    }

    private function usesDecisionModels(): bool
    {
        return $this->decisionModelClasses !== []
            || $this->handlers->anyTakesPartInDecisionMaking()
            || $this->annotationFinder->findAnnotatedMethods(DecisionBoundary::class) !== [];
    }

    /**
     * @param array<class-string, array{tagNames: string[], handledEventClasses: class-string[], aggregate?: array{className: class-string, aggregateType: string, streamName: string, identifierNames: string[]}}> $rawDefinitions
     * @param string[] $filterOnlyTagNames
     */
    private static function assertNoModelScopedOnlyByFilterOnlyTags(array $rawDefinitions, array $filterOnlyTagNames): void
    {
        foreach ($rawDefinitions as $modelClass => $rawDefinition) {
            if (isset($rawDefinition['aggregate'])) {
                continue;
            }

            if (array_diff($rawDefinition['tagNames'], $filterOnlyTagNames) === []) {
                throw ConfigurationException::create(sprintf(
                    "DecisionModel %s is scoped only by filter-only tag(s) '%s', which are never counted, so its handler's append would be guarded by nothing. Add a counted tag to the model's scope with #[DecisionModel(tags: [...])], or stop declaring '%s' in DynamicConsistencyBoundaryConfiguration::withFilterOnlyTags().",
                    $modelClass,
                    implode("', '", $rawDefinition['tagNames']),
                    implode("', '", $rawDefinition['tagNames']),
                ));
            }
        }
    }

    /**
     * @param array<class-string, array{thresholdTrigger: int, documentStore: string}> $snapshottedModelClasses
     * @param array<class-string, array{tagNames: string[], handledEventClasses: class-string[], aggregate?: array{className: class-string, aggregateType: string, streamName: string, identifierNames: string[]}}> $rawDefinitions
     */
    private static function assertEverySnapshottedClassIsADecisionModel(array $snapshottedModelClasses, array $rawDefinitions): void
    {
        foreach (array_keys($snapshottedModelClasses) as $modelClass) {
            if (isset($rawDefinitions[$modelClass])) {
                continue;
            }

            throw ConfigurationException::create(sprintf(
                'DynamicConsistencyBoundaryConfiguration::withSnapshotsFor() names %s, which is not a decision model, so there is nothing to snapshot. '
                . 'Add #[DecisionModel(tags: [...])] or #[DecisionModel(aggregate: ...)] to %s, or drop it from withSnapshotsFor().',
                $modelClass,
                $modelClass,
            ));
        }
    }

    private static function assertNoSagaFetchedIntoADecision(DecisionModelHandlers $handlers): void
    {
        foreach ($handlers->all() as $handler) {
            foreach ($handler->fetchedAggregateClasses() as $fetchedClass) {
                $reflectionClass = new ReflectionClass($fetchedClass);
                if ($reflectionClass->getAttributes(Saga::class) === [] && $reflectionClass->getAttributes(EventSourcingSaga::class) === []) {
                    continue;
                }

                throw ConfigurationException::create(sprintf(
                    'Saga %s is fetched into %s::%s, a Dynamic Consistency Boundary handler -- sagas are outside the boundary, so a decision taken on its state would be unguarded. '
                    . 'Keep the decision state in an aggregate or a decision model, or read the saga in a handler that does not take part in the boundary.',
                    $fetchedClass,
                    $handler->className(),
                    $handler->methodName(),
                ));
            }
        }
    }

    private static function assertNoAmbiguousDuplicateModelInjection(DecisionModelHandlers $handlers): void
    {
        foreach ($handlers->all() as $handler) {
            foreach ($handler->ambiguouslyDuplicatedModelClasses() as $modelClass) {
                throw ConfigurationException::create(sprintf(
                    '%s::%s injects %s more than once -- the naming convention alone cannot resolve which value each parameter should receive; use #[Fetch] on each occurrence.',
                    $handler->className(),
                    $handler->methodName(),
                    $modelClass,
                ));
            }
        }
    }
}
