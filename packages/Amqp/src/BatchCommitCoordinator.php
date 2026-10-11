<?php

declare(strict_types=1);

namespace Ecotone\Amqp;

use Ecotone\Messaging\Consumer\ConsumerPositionTracker;

/**
 * Coordinates batch commits for AMQP Stream position tracking
 *
 * Tracks messages processed in the current batch and determines when to commit
 * based on the configured commit interval.
 *
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
class BatchCommitCoordinator
{
    private int $messagesProcessedInBatch = 0;
    private ?string $lastProcessedOffset = null;
    private ?string $lastCommittedProcessedOffset = null;

    public function __construct(
        private int $commitInterval,
        private ConsumerPositionTracker $positionTracker,
        private string $consumerId,
    ) {
    }

    /**
     * Record that a message was processed and check if we should commit
     *
     * @param string $offset The stream offset of the processed message
     */
    public function recordMessageProcessed(string $offset): void
    {
        $this->messagesProcessedInBatch++;
        $this->lastProcessedOffset = $offset;
    }

    /**
     * Commit any pending offset from the previous batch before starting a new one
     * This ensures the last message in a batch is always committed
     *
     * @return void
     */
    public function commitPendingAndReset(bool $ignoreCommitInterval = false): void
    {
        if ($this->lastProcessedOffset === null) {
            return;
        }

        if ($this->isOffsetAlreadyCommitted()) {
            return;
        }

        if (! $ignoreCommitInterval && ($this->messagesProcessedInBatch % $this->commitInterval !== 0)) {
            return;
        }

        $nextOffset = (string)((int)$this->lastProcessedOffset + 1);
        $this->positionTracker->savePosition($this->consumerId, $nextOffset);

        $this->lastCommittedProcessedOffset = $this->lastProcessedOffset;
        $this->messagesProcessedInBatch = 0;
    }

    public function isOffsetAlreadyProcessed(string|int $offset): bool
    {
        return $this->lastCommittedProcessedOffset !== null && $offset <= $this->lastProcessedOffset;
    }

    public function getConsumerId(): string
    {
        return $this->consumerId;
    }

    private function isOffsetAlreadyCommitted(): bool
    {
        return $this->lastCommittedProcessedOffset !== null && $this->lastProcessedOffset <= $this->lastCommittedProcessedOffset;
    }
}
