<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\EventSourcing\EventStore;
use Ecotone\EventSourcing\Mapping\EventMapper;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageConverter\HeaderMapper;
use Ecotone\Modelling\AggregateFlow\SaveAggregate\SaveAggregateServiceTemplate;

/**
 * licence Enterprise
 */
final class DecisionModelAppendInterceptor
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly ConversionService $conversionService,
        private readonly HeaderMapper $headerMapper,
        private readonly EventMapper $eventMapper,
        private readonly EcotoneClockInterface $clock,
        private readonly EventBus $eventBus,
    ) {
    }

    public function append(MethodInvocation $methodInvocation, Message $message): mixed
    {
        $result = $methodInvocation->proceed();

        $appendCondition = DecisionModelLoadedState::appendConditionCarriedBy($message);

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
}
