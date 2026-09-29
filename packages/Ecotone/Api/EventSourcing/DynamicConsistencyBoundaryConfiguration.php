<?php

declare(strict_types=1);

namespace Ecotone\Api\EventSourcing;

use Ecotone\Api\Gateway\DocumentStore;

/**
 * licence Enterprise
 */
final class DynamicConsistencyBoundaryConfiguration
{
    public const DEFAULT_SNAPSHOT_TRIGGER_THRESHOLD = 100;

    /**
     * @param string[] $filterOnlyTagNames
     * @param array<class-string, array{thresholdTrigger: int, documentStore: string}> $snapshottedModelClasses
     * @param class-string[] $classesWithoutOptimisticLock
     */
    private function __construct(
        private readonly array $filterOnlyTagNames,
        private readonly array $snapshottedModelClasses = [],
        private readonly array $classesWithoutOptimisticLock = [],
    ) {
    }

    public static function createWithDefaults(): self
    {
        return new self([]);
    }

    /**
     * @param string[] $tagNames
     */
    public function withFilterOnlyTags(array $tagNames): self
    {
        return new self($tagNames, $this->snapshottedModelClasses, $this->classesWithoutOptimisticLock);
    }

    /**
     * State-stored aggregates that keep the 1.x last-write-wins behaviour: no counter tag is captured when
     * one is loaded and none is bumped when it is saved.
     *
     * @param class-string|class-string[] $stateStoredAggregateClasses
     */
    public function withoutOptimisticLockFor(array|string $stateStoredAggregateClasses): self
    {
        return new self(
            $this->filterOnlyTagNames,
            $this->snapshottedModelClasses,
            [...$this->classesWithoutOptimisticLock, ...(array) $stateStoredAggregateClasses],
        );
    }

    /**
     * @param class-string|class-string[] $modelClasses
     */
    public function withSnapshotsFor(
        array|string $modelClasses,
        int $thresholdTrigger = self::DEFAULT_SNAPSHOT_TRIGGER_THRESHOLD,
        string $documentStore = DocumentStore::class,
    ): self {
        $snapshottedModelClasses = $this->snapshottedModelClasses;
        foreach ((array) $modelClasses as $modelClass) {
            $snapshottedModelClasses[$modelClass] = [
                'thresholdTrigger' => $thresholdTrigger,
                'documentStore' => $documentStore,
            ];
        }

        return new self($this->filterOnlyTagNames, $snapshottedModelClasses, $this->classesWithoutOptimisticLock);
    }

    /**
     * @return string[]
     */
    public function filterOnlyTagNames(): array
    {
        return $this->filterOnlyTagNames;
    }

    /**
     * @return array<class-string, array{thresholdTrigger: int, documentStore: string}>
     */
    public function snapshottedModelClasses(): array
    {
        return $this->snapshottedModelClasses;
    }

    /**
     * @return class-string[]
     */
    public function classesWithoutOptimisticLock(): array
    {
        return $this->classesWithoutOptimisticLock;
    }
}
