<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

use Ecotone\Api\EventSourcing\AppendCondition;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\EventSourcing\EventStore;
use Ecotone\EventSourcing\Mapping\EventMapper;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageConverter\HeaderMapper;
use Ecotone\Modelling\AggregateFlow\SaveAggregate\SaveAggregateServiceTemplate;

use function get_class;
use function is_object;

/**
 * licence Enterprise
 */
final class DecisionModelAppendInterceptor
{
    /**
     * @param array<string, string> $decisionBoundaryMethods keyed by "Class::method", value is the boundary method name on that same class
     */
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly ConversionService $conversionService,
        private readonly HeaderMapper $headerMapper,
        private readonly EventMapper $eventMapper,
        private readonly EcotoneClockInterface $clock,
        private readonly EventBus $eventBus,
        private readonly array $decisionBoundaryMethods = [],
    ) {
    }

    public function append(MethodInvocation $methodInvocation, Message $message): mixed
    {
        $result = $methodInvocation->proceed();

        $appendCondition = $this->mergeDecisionBoundaryCondition($methodInvocation, DecisionModelLoadedState::appendConditionCarriedBy($message));

        if ($result === null || $result === []) {
            return $result;
        }

        $events = SaveAggregateServiceTemplate::buildEcotoneEvents(
            $result,
            $methodInvocation->getName(),
            $message,
            $this->headerMapper,
            $this->conversionService,
            $this->eventMapper,
            $this->clock,
        );

        if ($events === []) {
            return $result;
        }

        $streamName = DecisionModelStreamResolver::resolveFor(
            $methodInvocation->getObjectToInvokeOn(),
            $methodInvocation->getMethodName(),
        );

        $this->eventStore->appendTo(
            $streamName,
            $events,
            $appendCondition->isEmpty() ? null : $appendCondition,
        );

        foreach ($events as $event) {
            $this->eventBus->publish($event->getPayload(), $event->getMetadata());
        }

        return $result;
    }

    private function mergeDecisionBoundaryCondition(MethodInvocation $methodInvocation, AppendCondition $appendCondition): AppendCondition
    {
        $objectToInvokeOn = $methodInvocation->getObjectToInvokeOn();
        $className = is_object($objectToInvokeOn) ? get_class($objectToInvokeOn) : $objectToInvokeOn;
        $key = $className . '::' . $methodInvocation->getMethodName();

        if (! isset($this->decisionBoundaryMethods[$key])) {
            return $appendCondition;
        }

        $boundaryMethodName = $this->decisionBoundaryMethods[$key];
        $arguments = $methodInvocation->getArguments();
        $command = $arguments[0] ?? null;

        $criteria = $className::{$boundaryMethodName}($command);
        $loadedEvents = $this->eventStore->loadByCriteria($criteria);

        return $appendCondition->mergeWith($loadedEvents->appendCondition);
    }
}
