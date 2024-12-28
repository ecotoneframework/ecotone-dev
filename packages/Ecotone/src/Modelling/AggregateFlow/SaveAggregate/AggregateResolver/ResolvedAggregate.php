<?php

declare(strict_types=1);

namespace Ecotone\Modelling\AggregateFlow\SaveAggregate\AggregateResolver;

use Ecotone\Modelling\Event;

/**
 * licence Apache-2.0
 */
final class ResolvedAggregate
{
    /**
     * @param object $aggregateInstance
     * @param array<string, mixed> $identifiers
     * @param Event[] $events
     */
    public function __construct(
        private AggregateClassDefinition $aggregateClassDefinition,
        private bool                     $isNewInstance,
        private object                   $aggregateInstance,
        private ?int                     $versionBeforeHandling,
        private array                    $identifiers,
        private array                    $events,
    ) {
    }

    public function getAggregateClassName(): string
    {
        return $this->aggregateClassDefinition->getClassName();
    }

    public function isNewInstance(): bool
    {
        return $this->isNewInstance;
    }

    public function getAggregateClassDefinition(): AggregateClassDefinition
    {
        return $this->aggregateClassDefinition;
    }

    public function getIdentifiers(): array
    {
        return $this->identifiers;
    }

    public function getAggregateInstance(): object
    {
        return $this->aggregateInstance;
    }

    public function getEvents(): array
    {
        return $this->events;
    }

    public function getVersionBeforeHandling(): ?int
    {
        return $this->versionBeforeHandling;
    }

    /**
     * @param array<string, mixed> $identifiers
     */
    public function withIdentifiers(array $identifiers): self
    {
        return new self(
            $this->aggregateClassDefinition,
            $this->isNewInstance,
            $this->aggregateInstance,
            $this->versionBeforeHandling,
            $identifiers,
            $this->events
        );
    }
}