<?php

declare(strict_types=1);

namespace Ecotone\Api\EventSourcing;

use Ecotone\Api\Gateway\DocumentStore;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
final class DynamicConsistencyBoundaryConfiguration
{
    public const DEFAULT_SNAPSHOT_TRIGGER_THRESHOLD = 100;

    /**
     * @param string[] $filterOnlyTagNames
     * @param array<class-string, array{thresholdTrigger: int, documentStore: string}> $snapshottedModelClasses
     * @param class-string[] $classesWithoutCounterTag
     */
    private function __construct(
        private readonly array $filterOnlyTagNames,
        private readonly array $snapshottedModelClasses = [],
        private readonly array $classesWithoutCounterTag = [],
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
        return new self($tagNames, $this->snapshottedModelClasses, $this->classesWithoutCounterTag);
    }

    /**
     * @param class-string|class-string[] $stateStoredAggregateClasses
     */
    public function withoutCounterTagFor(array|string $stateStoredAggregateClasses): self
    {
        return new self(
            $this->filterOnlyTagNames,
            $this->snapshottedModelClasses,
            [...$this->classesWithoutCounterTag, ...(array) $stateStoredAggregateClasses],
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

        return new self($this->filterOnlyTagNames, $snapshottedModelClasses, $this->classesWithoutCounterTag);
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
    public function classesWithoutCounterTag(): array
    {
        return $this->classesWithoutCounterTag;
    }
}
