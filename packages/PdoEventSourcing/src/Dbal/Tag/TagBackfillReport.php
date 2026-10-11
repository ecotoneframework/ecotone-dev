<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Dbal\Tag;

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
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
final class TagBackfillReport
{
    private int $eventsScanned = 0;

    private int $eventsTagged = 0;

    private int $tagsBumped = 0;

    /** @var array<int> */
    private array $undeserializable = [];

    private function __construct(
        private int $lastNo,
    ) {
    }

    public static function startingFrom(?int $fromNo): self
    {
        return new self(($fromNo ?? 1) - 1);
    }

    public function recordScanned(TagBackfillBatch $batch): void
    {
        $this->eventsScanned += $batch->scannedCount();
        $this->lastNo = $batch->lastNo() ?? $this->lastNo;
        $this->undeserializable = [...$this->undeserializable, ...$batch->undeserializableNumbers()];
    }

    public function recordTagged(int $eventsTagged): void
    {
        $this->eventsTagged += $eventsTagged;
    }

    public function recordBumped(int $tagsBumped): void
    {
        $this->tagsBumped += $tagsBumped;
    }

    public function lastNo(): int
    {
        return $this->lastNo;
    }

    public function lastProcessedNo(): ?int
    {
        return $this->eventsScanned === 0 ? null : $this->lastNo;
    }

    public function eventsScanned(): int
    {
        return $this->eventsScanned;
    }

    public function eventsTagged(): int
    {
        return $this->eventsTagged;
    }

    public function tagsBumped(): int
    {
        return $this->tagsBumped;
    }

    /**
     * @return array<int>
     */
    public function undeserializableNumbers(): array
    {
        return $this->undeserializable;
    }
}
