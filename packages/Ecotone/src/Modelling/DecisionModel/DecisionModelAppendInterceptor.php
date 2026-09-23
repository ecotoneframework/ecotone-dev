<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

use Ecotone\Api\EventSourcing\AppendCondition;
use Ecotone\Api\EventSourcing\TaggedEventStore;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\EventSourcing\Mapping\EventMapper;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageConverter\HeaderMapper;
use Ecotone\Modelling\AggregateFlow\SaveAggregate\SaveAggregateServiceTemplate;
use Throwable;

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
        private readonly TaggedEventStore $taggedEventStore,
        private readonly DecisionModelAppendConditionCollector $collector,
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
        $messageId = $message->getHeaders()->getMessageId();

        try {
            $result = $methodInvocation->proceed();
        } catch (Throwable $exception) {
            $this->collector->consume($messageId);

            throw $exception;
        }

        $appendCondition = $this->collector->consume($messageId);
        $appendCondition = $this->mergeDecisionBoundaryCondition($methodInvocation, $appendCondition);

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

        $this->taggedEventStore->appendTo(
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
        $loadedEvents = $this->taggedEventStore->load($criteria);

        return $appendCondition->mergeWith($loadedEvents->appendCondition);
    }
}
