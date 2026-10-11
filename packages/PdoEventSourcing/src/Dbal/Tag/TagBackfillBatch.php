<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Dbal\Tag;

use Ecotone\EventSourcing\Tagging\EventsTags;

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
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
final class TagBackfillBatch
{
    /**
     * @param array<int> $undeserializableNumbers
     * @param string[] $eventIds
     */
    public function __construct(
        private readonly int $scannedCount,
        private readonly ?int $lastNo,
        private readonly array $undeserializableNumbers,
        private readonly array $eventIds,
        private readonly EventsTags $tags,
    ) {
    }

    public function scannedCount(): int
    {
        return $this->scannedCount;
    }

    public function lastNo(): ?int
    {
        return $this->lastNo;
    }

    /**
     * @return array<int>
     */
    public function undeserializableNumbers(): array
    {
        return $this->undeserializableNumbers;
    }

    public function hasTaggedEvents(): bool
    {
        return $this->tags->anyTagged();
    }

    public function taggedEventCount(): int
    {
        return $this->tags->taggedEventCount();
    }

    public function firstTaggedEventId(): string
    {
        return $this->eventIds[$this->tags->firstTaggedEventIndex()];
    }

    /**
     * @return string[]
     */
    public function eventIds(): array
    {
        return $this->eventIds;
    }

    public function tags(): EventsTags
    {
        return $this->tags;
    }
}
