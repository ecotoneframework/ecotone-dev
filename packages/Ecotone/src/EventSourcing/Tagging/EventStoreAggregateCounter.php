<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Tagging;

use Ecotone\Api\EventSourcing\AppendCondition;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\EventSourcing\EventStore;
use Ecotone\EventSourcing\EventStore\GuardedTagBump;
use Ecotone\Modelling\AggregateIdString;
use Ecotone\Modelling\Repository\AggregateCounter;

/**
 * licence Enterprise
 */
final class EventStoreAggregateCounter implements AggregateCounter
{
    public function __construct(
        private readonly AggregateCounterTags $aggregateCounterTags,
        private readonly EventStore $eventStore,
        private readonly GuardedTagBump $guardedTagBump,
    ) {
    }

    public function captureFor(string $aggregateClassName, array $identifiers): AppendCondition
    {
        $counterTag = $this->counterTagOf($aggregateClassName, $identifiers);

        return $counterTag === null ? AppendCondition::empty() : AppendCondition::fromCapturedVersions([$this->captureNow($counterTag)]);
    }

    public function bumpGuarded(string $aggregateClassName, array $identifiers, AppendCondition $capturedAtLoad): void
    {
        $counterTag = $this->counterTagOf($aggregateClassName, $identifiers);
        if ($counterTag === null) {
            return;
        }

        $this->guardedTagBump->bumpTagsGuarded(AppendCondition::fromCapturedVersions([
            $this->capturedVersionOf($counterTag, $capturedAtLoad) ?? $this->captureNow($counterTag),
        ]));
    }

    /**
     * @param array{name: string, value: string} $counterTag
     * @return array{name: string, value: string, expectedVersion: int}|null
     */
    private function capturedVersionOf(array $counterTag, AppendCondition $capturedAtLoad): ?array
    {
        foreach ($capturedAtLoad->expectedTagVersions() as $expectedTagVersion) {
            if ($expectedTagVersion['name'] === $counterTag['name'] && $expectedTagVersion['value'] === $counterTag['value']) {
                return $expectedTagVersion;
            }
        }

        return null;
    }

    /**
     * @param array{name: string, value: string} $counterTag
     * @return array{name: string, value: string, expectedVersion: int}
     */
    private function captureNow(array $counterTag): array
    {
        return $this->eventStore->loadByCriteria(EventCriteria::tag($counterTag['name'], $counterTag['value']))->appendCondition->expectedTagVersions()[0];
    }

    /**
     * @param array<string, mixed> $identifiers
     * @return array{name: string, value: string}|null
     */
    private function counterTagOf(string $aggregateClassName, array $identifiers): ?array
    {
        $aggregateType = $this->aggregateCounterTags->aggregateTypeOfClass($aggregateClassName);

        return $aggregateType === null ? null : $this->aggregateCounterTags->counterTagOf($aggregateType, AggregateIdString::from($identifiers));
    }
}
