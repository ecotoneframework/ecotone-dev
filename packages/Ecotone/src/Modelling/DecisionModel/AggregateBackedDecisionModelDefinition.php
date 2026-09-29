<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

/**
 * licence Enterprise
 */
final class AggregateBackedDecisionModelDefinition
{
    /**
     * @param class-string $className
     * @param class-string $aggregateClassName
     * @param string[] $identifierNames
     * @param class-string[] $handledEventClasses
     */
    public function __construct(
        private readonly string $className,
        private readonly string $aggregateClassName,
        private readonly string $aggregateType,
        private readonly string $streamName,
        private readonly array $identifierNames,
        private readonly array $handledEventClasses,
    ) {
    }

    public function className(): string
    {
        return $this->className;
    }

    public function aggregateClassName(): string
    {
        return $this->aggregateClassName;
    }

    public function aggregateType(): string
    {
        return $this->aggregateType;
    }

    public function streamName(): string
    {
        return $this->streamName;
    }

    /**
     * @return string[]
     */
    public function identifierNames(): array
    {
        return $this->identifierNames;
    }

    /**
     * @return class-string[]
     */
    public function handledEventClasses(): array
    {
        return $this->handledEventClasses;
    }

    /**
     * @param array<string, mixed> $identifiers
     */
    public function instanceFor(array $identifiers): AggregateBackedDecisionModelInstance
    {
        return new AggregateBackedDecisionModelInstance(
            $this->className,
            $this->aggregateClassName,
            $this->aggregateType,
            $this->streamName,
            $identifiers,
            $this->handledEventClasses,
        );
    }
}
