<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\EventSourcing\Tagging\AggregateCounterTags;
use Ecotone\Messaging\Handler\ClosureExpression\AttributeExpressionExecutor;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\FetchAggregateConverter;
use Ecotone\Messaging\Message;
use Ecotone\Modelling\AggregateFlow\SaveAggregate\AggregateResolver\AggregateDefinitionRegistry;
use Ecotone\Modelling\AggregateIdString;

/**
 * licence Enterprise
 */
final class FetchedAggregateCounterCapture
{
    public function __construct(
        private readonly string $parameterName,
        private readonly string $aggregateClassName,
        private readonly AttributeExpressionExecutor $expressionExecutor,
        private readonly AggregateDefinitionRegistry $aggregateDefinitionRegistry,
        private readonly AggregateCounterTags $aggregateCounterTags,
    ) {
    }

    public function parameterName(): string
    {
        return $this->parameterName;
    }

    public function resolveCriteria(Message $message): ?EventCriteria
    {
        $identifiers = FetchAggregateConverter::identifiersFrom(
            $this->expressionExecutor->execute($message, ['value' => $message->getPayload()]),
            $this->aggregateClassName,
            $this->aggregateDefinitionRegistry,
        );
        $aggregateType = $this->aggregateCounterTags->aggregateTypeOfClass($this->aggregateClassName);

        if ($identifiers === null || $aggregateType === null) {
            return null;
        }

        $counterTag = $this->aggregateCounterTags->counterTagOf($aggregateType, AggregateIdString::from($identifiers));

        return EventCriteria::tag($counterTag['name'], $counterTag['value']);
    }
}
