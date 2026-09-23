<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel\Config;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\Api\EventSourcing\TaggedEventStore;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\EventSourcing\Mapping\EventMapper;
use Ecotone\EventSourcing\Tagging\EventTagRegistry;
use Ecotone\EventSourcing\Tagging\EventTagRegistryBuilder;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorBuilder;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\MessageConverter\DefaultHeaderMapper;
use Ecotone\Messaging\Precedence;
use Ecotone\Messaging\Support\LicensingException;
use Ecotone\Modelling\DecisionModel\DecisionModelAppendConditionCollector;
use Ecotone\Modelling\DecisionModel\DecisionModelAppendInterceptor;
use Ecotone\Modelling\DecisionModel\DecisionModelDefinitionBuilder;
use Ecotone\Modelling\DecisionModel\DecisionModelDefinitionRegistry;
use Ecotone\Modelling\DecisionModel\DecisionModelExecutorRegistry;
use Ecotone\Modelling\DecisionModel\DecisionModelReflection;
use Ecotone\Modelling\EventSourcingExecutor\EventSourcingHandlerExecutorBuilder;

use ReflectionAttribute;
use ReflectionClass;

use function array_keys;
use function array_map;
use function implode;
use function sprintf;

#[ModuleAnnotation]
/**
 * licence Enterprise
 */
final class DecisionModelModule extends NoExternalConfigurationModule implements AnnotationModule
{
    private const INTERCEPTOR_REFERENCE_NAME = 'decisionModel.appendInterceptor';

    /**
     * @param class-string[] $decisionModelClasses
     * @param array<class-string, array{tagNames: string[], handledEventClasses: class-string[]}> $rawDefinitions
     * @param array<array{class: class-string, method: string}> $appendEligibleMethods
     */
    private function __construct(
        private readonly array $decisionModelClasses,
        private readonly array $rawDefinitions,
        private readonly array $appendEligibleMethods,
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

        $appendEligibleMethods = self::findAppendEligibleMethods($annotationRegistrationService, $interfaceToCallRegistry);

        return new self($decisionModelClasses, $rawDefinitions, $appendEligibleMethods);
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        if ($this->decisionModelClasses !== [] && ! $messagingConfiguration->isRunningForEnterpriseLicence()) {
            throw LicensingException::create(sprintf(
                'Dynamic Consistency Boundary (#[DecisionModel] used on %s) requires Ecotone Enterprise Licence.',
                implode(', ', $this->decisionModelClasses)
            ));
        }

        $messagingConfiguration->registerServiceDefinition(
            DecisionModelDefinitionRegistry::class,
            new Definition(DecisionModelDefinitionRegistry::class, [$this->rawDefinitions], 'createWith'),
        );

        $messagingConfiguration->registerServiceDefinition(
            DecisionModelAppendConditionCollector::class,
            new Definition(DecisionModelAppendConditionCollector::class),
        );

        foreach (array_keys($this->rawDefinitions) as $modelClass) {
            $classDefinition = $interfaceToCallRegistry->getClassDefinitionFor(Type::create($modelClass));

            $messagingConfiguration->registerServiceDefinition(
                DecisionModelExecutorRegistry::serviceIdFor($modelClass),
                EventSourcingHandlerExecutorBuilder::createFor($classDefinition, $interfaceToCallRegistry),
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
            self::INTERCEPTOR_REFERENCE_NAME,
            new Definition(DecisionModelAppendInterceptor::class, [
                Reference::to(TaggedEventStore::class),
                Reference::to(DecisionModelAppendConditionCollector::class),
                Reference::to(ConversionService::REFERENCE_NAME),
                DefaultHeaderMapper::createAllHeadersMapping()->getDefinition(),
                Reference::to(EventMapper::class),
                Reference::to(EcotoneClockInterface::class),
                Reference::to(EventBus::class),
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
     * @return array<array{class: class-string, method: string}>
     */
    private static function findAppendEligibleMethods(AnnotationFinder $annotationFinder, InterfaceToCallRegistry $interfaceToCallRegistry): array
    {
        $pairs = [];
        foreach ([CommandHandler::class, EventHandler::class] as $handlerAnnotationClass) {
            foreach ($annotationFinder->findAnnotatedMethods($handlerAnnotationClass) as $annotatedMethod) {
                $className = $annotatedMethod->getClassName();
                $methodName = $annotatedMethod->getMethodName();

                if ((new ReflectionClass($className))->getAttributes(Aggregate::class, ReflectionAttribute::IS_INSTANCEOF) !== []) {
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
}
