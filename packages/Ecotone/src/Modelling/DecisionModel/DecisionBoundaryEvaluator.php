<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\DecisionBoundary;
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
     * @param DecisionModelHandler[] $handlersWithBoundaryMethod
     */
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly array $handlersWithBoundaryMethod,
    ) {
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

    public function conditionFor(MethodInvocation $handlerInvocation): AppendCondition
    {
        $objectToInvokeOn = $handlerInvocation->getObjectToInvokeOn();
        $className = is_object($objectToInvokeOn) ? get_class($objectToInvokeOn) : $objectToInvokeOn;

        $handler = $this->handlerFor($className, $handlerInvocation->getMethodName());
        if ($handler === null) {
            return AppendCondition::empty();
        }

        $command = $handlerInvocation->getArguments()[0] ?? null;
        $criteria = $className::{$handler->boundaryMethodName()}($command);

        return $this->eventStore->loadByCriteria($criteria)->appendCondition;
    }

    private function handlerFor(string $className, string $methodName): ?DecisionModelHandler
    {
        foreach ($this->handlersWithBoundaryMethod as $handler) {
            if ($handler->matches($className, $methodName)) {
                return $handler;
            }
        }

        return null;
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
    public static function assertEveryBoundaryMatchesAHandler(array $boundariesByClass, array $matchedBoundaries): void
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
