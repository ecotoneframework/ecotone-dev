<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel\Config;

use function array_diff;
use function array_keys;
use function array_map;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\DecisionBoundary;
use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Fetch;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\Api\Attribute\QueryHandler;
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
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ParameterConverterAnnotationFactory;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInterceptorBuilder;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\MessageConverter\DefaultHeaderMapper;
use Ecotone\Messaging\Precedence;
use Ecotone\Modelling\DecisionModel\CrossConnectionDecisionModelGuard;
use Ecotone\Modelling\DecisionModel\DecisionBoundaryEvaluator;
use Ecotone\Modelling\DecisionModel\DecisionModelAppendInterceptor;
use Ecotone\Modelling\DecisionModel\DecisionModelBatchLoader;
use Ecotone\Modelling\DecisionModel\DecisionModelConverterBuilder;
use Ecotone\Modelling\DecisionModel\DecisionModelDefinitionBuilder;
use Ecotone\Modelling\DecisionModel\DecisionModelDefinitionRegistry;
use Ecotone\Modelling\DecisionModel\DecisionModelExecutorRegistry;
use Ecotone\Modelling\DecisionModel\DecisionModelReflection;
use Ecotone\Modelling\DecisionModel\DecisionModelTagResolvabilityGuard;
use Ecotone\Modelling\EventSourcingExecutor\EventSourcingHandlerExecutorBuilder;

use function implode;

use ReflectionAttribute;
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
     * @param array<class-string, array{tagNames: string[], handledEventClasses: class-string[]}> $rawDefinitions
     * @param array<array{class: class-string, method: string}> $appendEligibleMethods
     * @param array<string, string> $decisionBoundaryMethods keyed by "Class::method", value is the boundary method name on that same class
     * @param array<string, Definition[]> $loaderDefinitionsByHandler keyed by "Class::method", value is a list of DecisionModelParameterLoader definitions
     */
    private function __construct(
        private readonly AnnotationFinder $annotationFinder,
        private readonly array $decisionModelClasses,
        private readonly array $rawDefinitions,
        private readonly array $appendEligibleMethods,
        private readonly array $decisionBoundaryMethods,
        private readonly array $loaderDefinitionsByHandler,
    ) {
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        $decisionModelClasses = $annotationRegistrationService->findAnnotatedClasses(DecisionModel::class);
        $eventTagRegistry = EventTagRegistry::createWith(EventTagRegistryBuilder::buildRawDefinitions($annotationRegistrationService));

        $rawDefinitions = [];
        foreach ($decisionModelClasses as $decisionModelClass) {
            $classDefinition = $interfaceToCallRegistry->getClassDefinitionFor(Type::create($decisionModelClass));
            $explicitTags = $classDefinition->findSingleClassAnnotation(Type::create(DecisionModel::class))?->tags ?? [];

            $definition = DecisionModelDefinitionBuilder::buildFor($classDefinition, $interfaceToCallRegistry, $eventTagRegistry, $explicitTags);

            $rawDefinitions[$decisionModelClass] = [
                'tagNames' => $definition->tagNames(),
                'handledEventClasses' => $definition->handledEventClasses(),
            ];
        }

        $decisionBoundaryMethods = DecisionBoundaryEvaluator::findBoundaryMethodsByHandler($annotationRegistrationService, $interfaceToCallRegistry);

        $appendEligibleMethods = self::findAppendEligibleMethods($annotationRegistrationService, $interfaceToCallRegistry, $decisionBoundaryMethods);

        $loaderDefinitionsByHandler = self::findModelLoaderDefinitionsByHandler($annotationRegistrationService, $interfaceToCallRegistry);

        return new self($annotationRegistrationService, $decisionModelClasses, $rawDefinitions, $appendEligibleMethods, $decisionBoundaryMethods, $loaderDefinitionsByHandler);
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        if (! DynamicConsistencyBoundary::resolveFrom($extensionObjects)->isEnabled() && $this->usesDecisionModels()) {
            throw DynamicConsistencyBoundaryDisabled::exception();
        }

        if ($this->decisionModelClasses !== []) {
            self::assertNoModelScopedOnlyByFilterOnlyTags($this->rawDefinitions, DynamicConsistencyBoundary::resolveFrom($extensionObjects)->filterOnlyTagNames());
            CrossConnectionDecisionModelGuard::assertNoCrossConnectionInjection(
                $this->annotationFinder,
                $interfaceToCallRegistry,
                $extensionObjects,
                $this->rawDefinitions,
            );
            self::assertNoAmbiguousDuplicateModelInjection($this->annotationFinder, $interfaceToCallRegistry);
            DecisionModelTagResolvabilityGuard::assertEveryModelTagResolvableFromItsMessage($this->annotationFinder, $interfaceToCallRegistry, $this->rawDefinitions);
        }

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

        foreach ($this->loaderDefinitionsByHandler as $handlerKey => $loaderDefinitions) {
            $batchLoaderReference = self::BATCH_LOADER_REFERENCE_PREFIX . $handlerKey;

            $messagingConfiguration->registerServiceDefinition(
                $batchLoaderReference,
                new Definition(DecisionModelBatchLoader::class, [
                    Reference::to(EventStore::RAW_REFERENCE),
                    Reference::to(TagResolver::class),
                    $loaderDefinitions,
                ]),
            );

            $messagingConfiguration->registerBeforeMethodInterceptor(
                MethodInterceptorBuilder::create(
                    Reference::to($batchLoaderReference),
                    $interfaceToCallRegistry->getFor(DecisionModelBatchLoader::class, 'load'),
                    Precedence::SYSTEM_PRECEDENCE_AFTER,
                    $handlerKey,
                    true,
                )
            );
        }

        if ($this->appendEligibleMethods === []) {
            return;
        }

        $pointcut = implode(' || ', array_map(
            static fn (array $pair): string => sprintf('%s::%s', $pair['class'], $pair['method']),
            $this->appendEligibleMethods
        ));

        $messagingConfiguration->registerServiceDefinition(
            DecisionBoundaryEvaluator::class,
            new Definition(DecisionBoundaryEvaluator::class, [
                Reference::to(EventStore::RAW_REFERENCE),
                $this->decisionBoundaryMethods,
            ]),
        );

        $messagingConfiguration->registerServiceDefinition(
            self::INTERCEPTOR_REFERENCE_NAME,
            new Definition(DecisionModelAppendInterceptor::class, [
                Reference::to(EventStore::RAW_REFERENCE),
                Reference::to(ConversionService::REFERENCE_NAME),
                DefaultHeaderMapper::createAllHeadersMapping()->getDefinition(),
                Reference::to(EventMapper::class),
                Reference::to(EcotoneClockInterface::class),
                Reference::to(EventBus::class),
                Reference::to(DecisionBoundaryEvaluator::class),
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

    private function usesDecisionModels(): bool
    {
        return $this->decisionModelClasses !== []
            || $this->decisionBoundaryMethods !== []
            || $this->loaderDefinitionsByHandler !== []
            || $this->annotationFinder->findAnnotatedMethods(DecisionBoundary::class) !== [];
    }

    /**
     * @param array<class-string, array{tagNames: string[], handledEventClasses: class-string[]}> $rawDefinitions
     * @param string[] $filterOnlyTagNames
     */
    private static function assertNoModelScopedOnlyByFilterOnlyTags(array $rawDefinitions, array $filterOnlyTagNames): void
    {
        foreach ($rawDefinitions as $modelClass => $rawDefinition) {
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

    private static function assertNoAmbiguousDuplicateModelInjection(AnnotationFinder $annotationFinder, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        foreach ([CommandHandler::class, EventHandler::class] as $handlerAnnotationClass) {
            foreach ($annotationFinder->findAnnotatedMethods($handlerAnnotationClass) as $annotatedMethod) {
                $className = $annotatedMethod->getClassName();
                $methodName = $annotatedMethod->getMethodName();
                $interfaceToCall = $interfaceToCallRegistry->getFor($className, $methodName);

                $occurrencesByModelClass = [];
                foreach ($interfaceToCall->getInterfaceParameters() as $parameter) {
                    if (! $parameter->isClassOrInterface() || ! DecisionModelReflection::isDecisionModel($parameter->getTypeHint())) {
                        continue;
                    }

                    $occurrencesByModelClass[$parameter->getTypeHint()][] = $parameter->hasAnnotation(Fetch::class);
                }

                foreach ($occurrencesByModelClass as $modelClass => $hasFetchPerParameter) {
                    if (count($hasFetchPerParameter) > 1 && in_array(false, $hasFetchPerParameter, true)) {
                        throw ConfigurationException::create(sprintf(
                            '%s::%s injects %s more than once -- the naming convention alone cannot resolve which value each parameter should receive; use #[Fetch] on each occurrence.',
                            $className,
                            $methodName,
                            $modelClass,
                        ));
                    }
                }
            }
        }
    }

    /**
     * @param array<string, string> $decisionBoundaryMethods
     * @return array<array{class: class-string, method: string}>
     */
    private static function findAppendEligibleMethods(AnnotationFinder $annotationFinder, InterfaceToCallRegistry $interfaceToCallRegistry, array $decisionBoundaryMethods): array
    {
        $pairs = [];
        foreach ([CommandHandler::class, EventHandler::class] as $handlerAnnotationClass) {
            foreach ($annotationFinder->findAnnotatedMethods($handlerAnnotationClass) as $annotatedMethod) {
                $className = $annotatedMethod->getClassName();
                $methodName = $annotatedMethod->getMethodName();

                if ((new ReflectionClass($className))->getAttributes(Aggregate::class, ReflectionAttribute::IS_INSTANCEOF) !== []) {
                    continue;
                }

                if (isset($decisionBoundaryMethods[$className . '::' . $methodName])) {
                    $pairs[] = ['class' => $className, 'method' => $methodName];
                    continue;
                }

                $interfaceToCall = $interfaceToCallRegistry->getFor($className, $methodName);

                $hasModelParameter = false;
                foreach ($interfaceToCall->getInterfaceParameters() as $parameter) {
                    if ($parameter->isClassOrInterface() && DecisionModelReflection::isDecisionModel($parameter->getTypeHint())) {
                        $hasModelParameter = true;
                        break;
                    }
                }

                if ($hasModelParameter) {
                    $pairs[] = ['class' => $className, 'method' => $methodName];
                }
            }
        }

        return $pairs;
    }

    /**
     * @return array<string, Definition[]> keyed by "Class::method", value is a list of DecisionModelParameterLoader definitions,
     *         one per injected #[DecisionModel] parameter, for every service, aggregate, and query handler alike
     */
    private static function findModelLoaderDefinitionsByHandler(AnnotationFinder $annotationFinder, InterfaceToCallRegistry $interfaceToCallRegistry): array
    {
        $loaderDefinitionsByHandler = [];
        foreach ([CommandHandler::class, EventHandler::class, QueryHandler::class] as $handlerAnnotationClass) {
            foreach ($annotationFinder->findAnnotatedMethods($handlerAnnotationClass) as $annotatedMethod) {
                $className = $annotatedMethod->getClassName();
                $methodName = $annotatedMethod->getMethodName();
                $handlerKey = $className . '::' . $methodName;

                if (isset($loaderDefinitionsByHandler[$handlerKey])) {
                    continue;
                }

                $loaderDefinitions = self::findModelLoaderDefinitionsFor($interfaceToCallRegistry->getFor($className, $methodName));

                if ($loaderDefinitions !== []) {
                    $loaderDefinitionsByHandler[$handlerKey] = $loaderDefinitions;
                }
            }
        }

        return $loaderDefinitionsByHandler;
    }

    /**
     * @return Definition[]
     */
    private static function findModelLoaderDefinitionsFor(InterfaceToCall $interfaceToCall): array
    {
        $loaderDefinitions = [];
        foreach ($interfaceToCall->getInterfaceParameters() as $parameter) {
            $converterBuilder = ParameterConverterAnnotationFactory::getConverterFor($parameter, $interfaceToCall);

            if ($converterBuilder instanceof DecisionModelConverterBuilder) {
                $loaderDefinitions[] = $converterBuilder->compileLoader($interfaceToCall);
            }
        }

        return $loaderDefinitions;
    }
}
