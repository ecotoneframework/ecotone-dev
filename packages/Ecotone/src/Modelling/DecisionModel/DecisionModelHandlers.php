<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

use function array_filter;
use function array_map;
use function array_values;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Fetch;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ParameterConverterAnnotationFactory;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\FetchAggregateConverterBuilder;

use function in_array;

use ReflectionAttribute;
use ReflectionClass;

/**
 * licence Enterprise
 */
final class DecisionModelHandlers
{
    /**
     * @param array<string, DecisionModelHandler> $handlers keyed by DecisionModelHandler::key()
     */
    private function __construct(private readonly array $handlers)
    {
    }

    public static function scan(AnnotationFinder $annotationFinder, InterfaceToCallRegistry $interfaceToCallRegistry): self
    {
        $boundariesByClass = DecisionBoundaryEvaluator::boundaryMethodsByCommandTypePerClass($annotationFinder, $interfaceToCallRegistry);

        $handlers = [];
        $matchedBoundaries = [];
        foreach ([CommandHandler::class, EventHandler::class, QueryHandler::class] as $handlerAnnotationClass) {
            foreach ($annotationFinder->findAnnotatedMethods($handlerAnnotationClass) as $annotatedMethod) {
                $className = $annotatedMethod->getClassName();
                $methodName = $annotatedMethod->getMethodName();
                $key = DecisionModelHandler::keyFor($className, $methodName);

                if (isset($handlers[$key])) {
                    continue;
                }

                $interfaceToCall = $interfaceToCallRegistry->getFor($className, $methodName);

                [$modelLoaderDefinitions, $fetchedAggregateConverters] = self::decisionConvertersOf($interfaceToCall);
                $ambiguouslyDuplicatedModelClasses = self::ambiguouslyDuplicatedModelClassesIn($interfaceToCall, $handlerAnnotationClass);

                $boundaryMethodName = $handlerAnnotationClass === QueryHandler::class
                    ? null
                    : self::boundaryMethodFor($boundariesByClass, $interfaceToCall, $className);

                if ($boundaryMethodName !== null) {
                    $matchedBoundaries[$className][$interfaceToCall->getFirstParameter()->getTypeHint()] = true;
                }

                $appendsItsResult = $handlerAnnotationClass !== QueryHandler::class
                    && ! self::isDeclaredOnAggregate($className)
                    && ($boundaryMethodName !== null || self::injectsDecisionModel($interfaceToCall));

                if ($modelLoaderDefinitions === [] && $boundaryMethodName === null && ! $appendsItsResult && $ambiguouslyDuplicatedModelClasses === []) {
                    continue;
                }

                $joinsFetchedAggregates = $handlerAnnotationClass !== QueryHandler::class && ($appendsItsResult || $modelLoaderDefinitions !== []);

                $handlers[$key] = new DecisionModelHandler(
                    $className,
                    $methodName,
                    $modelLoaderDefinitions,
                    $boundaryMethodName,
                    $appendsItsResult,
                    $ambiguouslyDuplicatedModelClasses,
                    $joinsFetchedAggregates ? array_map(static fn (FetchAggregateConverterBuilder $converter): Definition => $converter->compileCounterCapture(), $fetchedAggregateConverters) : [],
                    $joinsFetchedAggregates ? array_map(static fn (FetchAggregateConverterBuilder $converter): string => $converter->aggregateClassName(), $fetchedAggregateConverters) : [],
                    $appendsItsResult && $boundaryMethodName !== null ? [DecisionBoundaryEvaluator::definitionFor($className, $boundaryMethodName, $interfaceToCall)] : [],
                );
            }
        }

        DecisionBoundaryEvaluator::assertEveryBoundaryMatchesAHandler($boundariesByClass, $matchedBoundaries);

        return new self($handlers);
    }

    /**
     * @return DecisionModelHandler[]
     */
    public function all(): array
    {
        return array_values($this->handlers);
    }

    /**
     * @return DecisionModelHandler[]
     */
    public function loadingBeforeInvocation(): array
    {
        return array_values(array_filter($this->handlers, static fn (DecisionModelHandler $handler): bool => $handler->loadsBeforeInvocation()));
    }

    /**
     * @return DecisionModelHandler[]
     */
    public function appendingTheirResult(): array
    {
        return array_values(array_filter($this->handlers, static fn (DecisionModelHandler $handler): bool => $handler->appendsItsResult()));
    }

    public function anyTakesPartInDecisionMaking(): bool
    {
        return $this->handlers !== [];
    }

    /**
     * @param array<string, array<string, string>> $boundariesByClass
     */
    private static function boundaryMethodFor(array $boundariesByClass, InterfaceToCall $interfaceToCall, string $className): ?string
    {
        if (! isset($boundariesByClass[$className]) || $interfaceToCall->getInterfaceParameterAmount() === 0) {
            return null;
        }

        return $boundariesByClass[$className][$interfaceToCall->getFirstParameter()->getTypeHint()] ?? null;
    }

    private static function isDeclaredOnAggregate(string $className): bool
    {
        return (new ReflectionClass($className))->getAttributes(Aggregate::class, ReflectionAttribute::IS_INSTANCEOF) !== [];
    }

    private static function injectsDecisionModel(InterfaceToCall $interfaceToCall): bool
    {
        foreach ($interfaceToCall->getInterfaceParameters() as $parameter) {
            if ($parameter->isClassOrInterface() && DecisionModelReflection::isDecisionModel($parameter->getTypeHint())) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: Definition[], 1: FetchAggregateConverterBuilder[]}
     */
    private static function decisionConvertersOf(InterfaceToCall $interfaceToCall): array
    {
        $loaderDefinitions = [];
        $fetchedAggregateConverters = [];
        foreach ($interfaceToCall->getInterfaceParameters() as $parameter) {
            $converterBuilder = ParameterConverterAnnotationFactory::getConverterFor($parameter, $interfaceToCall);

            if ($converterBuilder instanceof DecisionModelConverterBuilder) {
                $loaderDefinitions[] = $converterBuilder->compileLoader($interfaceToCall);
            } elseif ($converterBuilder instanceof FetchAggregateConverterBuilder) {
                $fetchedAggregateConverters[] = $converterBuilder;
            }
        }

        return [$loaderDefinitions, $fetchedAggregateConverters];
    }

    /**
     * @return class-string[]
     */
    private static function ambiguouslyDuplicatedModelClassesIn(InterfaceToCall $interfaceToCall, string $handlerAnnotationClass): array
    {
        if ($handlerAnnotationClass === QueryHandler::class) {
            return [];
        }

        $occurrencesByModelClass = [];
        foreach ($interfaceToCall->getInterfaceParameters() as $parameter) {
            if (! $parameter->isClassOrInterface() || ! DecisionModelReflection::isDecisionModel($parameter->getTypeHint())) {
                continue;
            }

            $occurrencesByModelClass[$parameter->getTypeHint()][] = $parameter->hasAnnotation(Fetch::class);
        }

        $ambiguous = [];
        foreach ($occurrencesByModelClass as $modelClass => $hasFetchPerParameter) {
            if (count($hasFetchPerParameter) > 1 && in_array(false, $hasFetchPerParameter, true)) {
                $ambiguous[] = $modelClass;
            }
        }

        return $ambiguous;
    }
}
