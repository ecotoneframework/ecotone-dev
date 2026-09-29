<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Modelling\AggregateIdString;
use Ecotone\Modelling\DecisionModel\Snapshot\DecisionModelFoldShape;

use function sprintf;

/**
 * licence Enterprise
 */
final class AggregateBackedDecisionModelInstance
{
    /**
     * @param class-string $modelClassName
     * @param class-string $aggregateClassName
     * @param array<string, mixed> $identifiers
     * @param class-string[] $handledEventClasses
     */
    public function __construct(
        private readonly string $modelClassName,
        private readonly string $aggregateClassName,
        private readonly string $aggregateType,
        private readonly string $streamName,
        private readonly array $identifiers,
        private readonly array $handledEventClasses,
    ) {
    }

    public function instanceKey(): string
    {
        return sprintf('%s|%s|%s', $this->streamName, $this->aggregateType, $this->aggregateId());
    }

    public function snapshotScopeKey(): string
    {
        return sprintf('%s|%s', $this->aggregateType, $this->aggregateId());
    }

    public function foldShape(): string
    {
        return DecisionModelFoldShape::of($this->modelClassName, $this->handledEventClasses, [$this->aggregateType]);
    }

    public function modelClassName(): string
    {
        return $this->modelClassName;
    }

    public function captureCriteria(): EventCriteria
    {
        return EventCriteria::aggregate($this->aggregateClassName, $this->identifiers);
    }

    public function streamName(): string
    {
        return $this->streamName;
    }

    public function aggregateType(): string
    {
        return $this->aggregateType;
    }

    public function aggregateId(): string
    {
        return AggregateIdString::from($this->identifiers);
    }

    /**
     * @return class-string[]
     */
    public function handledEventClasses(): array
    {
        return $this->handledEventClasses;
    }

    public function describeInstance(): string
    {
        return sprintf('%s %s', $this->aggregateType, $this->aggregateId());
    }
}
