<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

use function array_keys;
use function array_map;
use function array_slice;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\DecisionBoundary;
use Ecotone\Api\Attribute\Fetch;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\EventSourcing\Tagging\EventTagRegistry;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ParameterConverterAnnotationFactory;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Handler\InterfaceParameter;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\ParameterConverter;
use Ecotone\Messaging\Handler\ParameterConverterBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\PayloadBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\ReferenceBuilder;
use Ecotone\Messaging\Message;

use function implode;
use function sprintf;

/**
 * licence Enterprise
 */
final class DecisionBoundaryEvaluator
{
    /**
     * @param ParameterConverter[] $parameterConverters the command converter first, then one per additional boundary parameter
     */
    public function __construct(
        private readonly string $className,
        private readonly string $boundaryMethodName,
        private readonly array $parameterConverters,
        private readonly EventTagRegistry $eventTagRegistry,
    ) {
    }

    public static function definitionFor(string $className, string $boundaryMethodName, InterfaceToCall $handler, InterfaceToCall $boundary): Definition
    {
        return new Definition(self::class, [
            $className,
            $boundaryMethodName,
            [
                PayloadBuilder::create($handler->getFirstParameter()->getName())->compile($handler),
                ...self::additionalParameterDefinitionsOf($boundary),
            ],
            Reference::to(EventTagRegistry::class),
        ]);
    }

    public function decidedByLabel(): string
    {
        return $this->className . '::' . $this->boundaryMethodName;
    }

    public function criteriaFor(Message $message): EventCriteria
    {
        $criteria = $this->className::{$this->boundaryMethodName}(...array_map(
            static fn (ParameterConverter $parameterConverter): mixed => $parameterConverter->getArgumentFrom($message),
            $this->parameterConverters,
        ));

        $this->assertNotScopedOnlyByFilterOnlyTags($criteria);

        return $criteria;
    }

    private function assertNotScopedOnlyByFilterOnlyTags(EventCriteria $criteria): void
    {
        $tagNames = [];
        foreach ($criteria->branches() as $branch) {
            foreach ($branch->tags() as $tag) {
                if (! $this->eventTagRegistry->isFilterOnly($tag['name'])) {
                    return;
                }

                $tagNames[$tag['name']] = true;
            }
        }

        if ($tagNames === []) {
            return;
        }

        throw ConfigurationException::create(sprintf(
            "#[DecisionBoundary] %s::%s returns criteria scoped only by filter-only tag(s) '%s', which are never counted, so its handler's append would be guarded by nothing. Add a counted tag to the criteria the boundary returns, or stop declaring '%s' in DynamicConsistencyBoundaryConfiguration::withFilterOnlyTags().",
            $this->className,
            $this->boundaryMethodName,
            implode("', '", array_keys($tagNames)),
            implode("', '", array_keys($tagNames)),
        ));
    }

    /**
     * @return array<string, array<string, string>> keyed by class name, then by the command type hint, value is the boundary method name
     */
    public static function boundaryMethodsByCommandTypePerClass(AnnotationFinder $annotationFinder, InterfaceToCallRegistry $interfaceToCallRegistry): array
    {
        $boundariesByClass = [];
        foreach ($annotationFinder->findAnnotatedMethods(DecisionBoundary::class) as $annotatedMethod) {
            $className = $annotatedMethod->getClassName();
            $boundaryMethodName = $annotatedMethod->getMethodName();

            $boundaryInterfaceToCall = $interfaceToCallRegistry->getFor($className, $boundaryMethodName);
            self::assertBoundaryShape($boundaryInterfaceToCall);

            $commandTypeHint = $boundaryInterfaceToCall->getFirstParameter()->getTypeHint();
            if (isset($boundariesByClass[$className][$commandTypeHint])) {
                throw ConfigurationException::create(sprintf(
                    '#[DecisionBoundary] %s::%s and %s::%s both take %s -- a handler can have only one boundary. Merge them into one method, combining their criteria with EventCriteria::or().',
                    $className,
                    $boundariesByClass[$className][$commandTypeHint],
                    $className,
                    $boundaryMethodName,
                    $commandTypeHint,
                ));
            }

            $boundariesByClass[$className][$commandTypeHint] = $boundaryMethodName;
        }

        return $boundariesByClass;
    }

    private static function assertBoundaryShape(InterfaceToCall $boundary): void
    {
        if (! $boundary->isStaticallyCalled()) {
            throw ConfigurationException::create(sprintf(
                '#[DecisionBoundary] %s::%s must be static -- it is called with the handler\'s command, before any instance is involved. Services it needs arrive as parameters.',
                $boundary->getInterfaceName(),
                $boundary->getMethodName(),
            ));
        }

        if ($boundary->getInterfaceParameterAmount() === 0 || ! $boundary->getFirstParameter()->isClassOrInterface()) {
            throw ConfigurationException::create(sprintf(
                '#[DecisionBoundary] %s::%s must take the command or event of the handler it scopes as its first parameter.',
                $boundary->getInterfaceName(),
                $boundary->getMethodName(),
            ));
        }

        if ($boundary->getReturnType()?->toString() !== EventCriteria::class) {
            throw ConfigurationException::create(sprintf(
                '#[DecisionBoundary] %s::%s must declare %s as its return type.',
                $boundary->getInterfaceName(),
                $boundary->getMethodName(),
                EventCriteria::class,
            ));
        }

        self::assertNoParameterDependsOnTheRead($boundary);
    }

    private static function assertNoParameterDependsOnTheRead(InterfaceToCall $boundary): void
    {
        foreach (array_slice($boundary->getInterfaceParameters(), 1) as $parameter) {
            if ($parameter->hasAnnotation(Fetch::class)) {
                throw ConfigurationException::create(sprintf(
                    '#[DecisionBoundary] %s::%s takes $%s marked with #[Fetch] -- the boundary is evaluated before the read it scopes, so it cannot receive a fetched aggregate or decision model. Fetch it in the handler this boundary scopes instead.',
                    $boundary->getInterfaceName(),
                    $boundary->getMethodName(),
                    $parameter->getName(),
                ));
            }

            if ($parameter->isClassOrInterface() && DecisionModelReflection::isDecisionModel($parameter->getTypeHint())) {
                throw ConfigurationException::create(sprintf(
                    '#[DecisionBoundary] %s::%s takes $%s typed with the decision model %s -- the boundary is evaluated before the read it scopes, so it cannot receive a decision model. Inject the model into the handler this boundary scopes instead.',
                    $boundary->getInterfaceName(),
                    $boundary->getMethodName(),
                    $parameter->getName(),
                    $parameter->getTypeHint(),
                ));
            }
        }
    }

    /**
     * @return Definition[]
     */
    private static function additionalParameterDefinitionsOf(InterfaceToCall $boundary): array
    {
        $definitions = [];
        foreach (array_slice($boundary->getInterfaceParameters(), 1) as $parameter) {
            $definitions[] = self::converterBuilderFor($parameter, $boundary)->compile($boundary);
        }

        return $definitions;
    }

    private static function converterBuilderFor(InterfaceParameter $parameter, InterfaceToCall $boundary): ParameterConverterBuilder
    {
        $converterBuilder = ParameterConverterAnnotationFactory::getConverterFor($parameter, $boundary);

        if ($converterBuilder !== null) {
            return $converterBuilder;
        }

        if ($parameter->isClassOrInterface()) {
            return ReferenceBuilder::create($parameter->getName(), $parameter->getTypeHint());
        }

        throw ConfigurationException::create(sprintf(
            '#[DecisionBoundary] %s::%s takes $%s, which no parameter rule resolves. Mark it with #[Header], #[Headers], #[ConfigurationVariable] or #[Reference], or type it with the service class to inject.',
            $boundary->getInterfaceName(),
            $boundary->getMethodName(),
            $parameter->getName(),
        ));
    }

    /**
     * @param array<string, array<string, string>> $boundariesByClass
     * @param array<string, array<string, true>> $matchedBoundaries
     * @param array<string, array<string, true>> $queryHandledTypesByClass
     */
    public static function assertEveryBoundaryMatchesAHandler(array $boundariesByClass, array $matchedBoundaries, array $queryHandledTypesByClass): void
    {
        foreach ($boundariesByClass as $className => $boundaryMethodsByCommandType) {
            foreach ($boundaryMethodsByCommandType as $commandTypeHint => $boundaryMethodName) {
                if (isset($matchedBoundaries[$className][$commandTypeHint])) {
                    continue;
                }

                if (isset($queryHandledTypesByClass[$className][$commandTypeHint])) {
                    throw ConfigurationException::create(sprintf(
                        '#[DecisionBoundary] %s::%s scopes %s, which %s handles with a #[QueryHandler] -- a query appends no events, so the boundary would guard nothing. Remove the boundary, or move it to the #[CommandHandler] or #[EventHandler] that appends.',
                        $className,
                        $boundaryMethodName,
                        $commandTypeHint,
                        $className,
                    ));
                }

                throw ConfigurationException::create(sprintf(
                    '#[DecisionBoundary] %s::%s scopes no handler: no #[CommandHandler] or #[EventHandler] of %s takes %s as its first parameter. Declare the boundary in the handler\'s class, taking the same command or event as the handler.',
                    $className,
                    $boundaryMethodName,
                    $className,
                    $commandTypeHint,
                ));
            }
        }
    }
}
