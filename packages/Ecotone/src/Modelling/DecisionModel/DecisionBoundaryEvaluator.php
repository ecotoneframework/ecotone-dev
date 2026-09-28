<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\DecisionBoundary;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\EventSourcing\AppendCondition;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;

use function get_class;
use function is_object;

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
            $firstParameterTypeHint = $boundaryInterfaceToCall->getInterfaceParameterAmount() > 0
                ? $boundaryInterfaceToCall->getFirstParameter()->getTypeHint()
                : null;

            $boundariesByClass[$className][$firstParameterTypeHint] = $boundaryMethodName;
        }

        $boundaryMethodsByHandler = [];
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

                if (isset($boundariesByClass[$className][$firstParameterTypeHint])) {
                    $boundaryMethodsByHandler[$className . '::' . $methodName] = $boundariesByClass[$className][$firstParameterTypeHint];
                }
            }
        }

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
}
