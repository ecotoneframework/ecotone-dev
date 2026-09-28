<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\DecisionBoundary;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\EventSourcing\AppendCondition;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;

use function get_class;
use function is_object;
use function sprintf;

/**
 * licence Enterprise
 */
final class DecisionBoundaryEvaluator
{
    /**
     * @param array<string, string> $boundaryMethodsByHandler keyed by "Class::method" of the handler, value is the #[DecisionBoundary] method name on that same class
     */
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly array $boundaryMethodsByHandler,
    ) {
    }

    /**
     * A handler is matched to the #[DecisionBoundary] method of its class whose first parameter has the same type as the handler's first parameter.
     *
     * @return array<string, string> keyed by "Class::method" of the handler, value is the boundary method name
     */
    public static function findBoundaryMethodsByHandler(AnnotationFinder $annotationFinder, InterfaceToCallRegistry $interfaceToCallRegistry): array
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

        $boundaryMethodsByHandler = [];
        $matchedBoundaries = [];
        foreach ([CommandHandler::class, EventHandler::class] as $handlerAnnotationClass) {
            foreach ($annotationFinder->findAnnotatedMethods($handlerAnnotationClass) as $annotatedMethod) {
                $className = $annotatedMethod->getClassName();
                $methodName = $annotatedMethod->getMethodName();

                if (! isset($boundariesByClass[$className])) {
                    continue;
                }

                $interfaceToCall = $interfaceToCallRegistry->getFor($className, $methodName);
                $firstParameterTypeHint = $interfaceToCall->getInterfaceParameterAmount() > 0
                    ? $interfaceToCall->getFirstParameter()->getTypeHint()
                    : null;

                if ($firstParameterTypeHint !== null && isset($boundariesByClass[$className][$firstParameterTypeHint])) {
                    $boundaryMethodsByHandler[$className . '::' . $methodName] = $boundariesByClass[$className][$firstParameterTypeHint];
                    $matchedBoundaries[$className][$firstParameterTypeHint] = true;
                }
            }
        }

        self::assertEveryBoundaryMatchesAHandler($boundariesByClass, $matchedBoundaries);

        return $boundaryMethodsByHandler;
    }

    public function conditionFor(MethodInvocation $handlerInvocation): AppendCondition
    {
        $objectToInvokeOn = $handlerInvocation->getObjectToInvokeOn();
        $className = is_object($objectToInvokeOn) ? get_class($objectToInvokeOn) : $objectToInvokeOn;
        $handlerKey = $className . '::' . $handlerInvocation->getMethodName();

        if (! isset($this->boundaryMethodsByHandler[$handlerKey])) {
            return AppendCondition::empty();
        }

        $command = $handlerInvocation->getArguments()[0] ?? null;
        $criteria = $className::{$this->boundaryMethodsByHandler[$handlerKey]}($command);

        return $this->eventStore->loadByCriteria($criteria)->appendCondition;
    }

    private static function assertBoundaryShape(InterfaceToCall $boundary): void
    {
        if (! $boundary->isStaticallyCalled()) {
            throw ConfigurationException::create(sprintf(
                '#[DecisionBoundary] %s::%s must be static -- it is called with the handler\'s command, before any instance is involved.',
                $boundary->getInterfaceName(),
                $boundary->getMethodName(),
            ));
        }

        if ($boundary->getInterfaceParameterAmount() !== 1 || ! $boundary->getFirstParameter()->isClassOrInterface()) {
            throw ConfigurationException::create(sprintf(
                '#[DecisionBoundary] %s::%s must take exactly one parameter -- the command or event of the handler it scopes, as its first parameter.',
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
    }

    /**
     * @param array<string, array<string, string>> $boundariesByClass
     * @param array<string, array<string, true>> $matchedBoundaries
     */
    private static function assertEveryBoundaryMatchesAHandler(array $boundariesByClass, array $matchedBoundaries): void
    {
        foreach ($boundariesByClass as $className => $boundaryMethodsByCommandType) {
            foreach ($boundaryMethodsByCommandType as $commandTypeHint => $boundaryMethodName) {
                if (! isset($matchedBoundaries[$className][$commandTypeHint])) {
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
}
