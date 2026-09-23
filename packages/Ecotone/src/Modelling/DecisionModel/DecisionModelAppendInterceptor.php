<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

use Ecotone\Api\EventSourcing\TaggedEventStore;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\EventSourcing\Mapping\EventMapper;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageConverter\HeaderMapper;
use Ecotone\Modelling\AggregateFlow\SaveAggregate\AggregateResolver\AggregateDefinitionResolver;
use Ecotone\Modelling\AggregateFlow\SaveAggregate\SaveAggregateServiceTemplate;
use Throwable;

/**
 * licence Enterprise
 */
final class DecisionModelAppendInterceptor
{
    public function __construct(
        private readonly TaggedEventStore $taggedEventStore,
        private readonly DecisionModelAppendConditionCollector $collector,
        private readonly ConversionService $conversionService,
        private readonly HeaderMapper $headerMapper,
        private readonly EventMapper $eventMapper,
        private readonly EcotoneClockInterface $clock,
        private readonly EventBus $eventBus,
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

        $this->taggedEventStore->appendTo(
            AggregateDefinitionResolver::DEFAULT_STREAM,
            $events,
            $appendCondition->isEmpty() ? null : $appendCondition,
        );

        foreach ($events as $event) {
            $this->eventBus->publish($event->getPayload(), $event->getMetadata());
        }

        return $result;
    }
}
