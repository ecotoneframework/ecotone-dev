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
 */
class BatchCommitCoordinator
{
    private int $messagesProcessedInBatch = 0;
    private ?string $lastProcessedOffset = null;

    public function __construct(
        private int $commitInterval,
        private ConsumerPositionTracker $positionTracker,
        private string $consumerId
    ) {
    }

    /**
     * Record that a message was processed and check if we should commit
     * 
     * @param string $offset The stream offset of the processed message
     * @return bool True if position should be committed now
     */
    public function recordMessageProcessed(string $offset): bool
    {
        $this->messagesProcessedInBatch++;
        $this->lastProcessedOffset = $offset;

        // Commit if we've reached the commit interval
        if ($this->messagesProcessedInBatch % $this->commitInterval === 0) {
            return true;
        }

        return false;
    }

    /**
     * Commit any pending offset from the previous batch before starting a new one
     * This ensures the last message in a batch is always committed
     *
     * @return void
     */
    public function commitPendingAndReset(): void
    {
        if ($this->lastProcessedOffset !== null) {
            $nextOffset = (string)((int)$this->lastProcessedOffset + 1);
            $this->positionTracker->savePosition($this->consumerId, $nextOffset);
        }

        $this->messagesProcessedInBatch = 0;
        $this->lastProcessedOffset = null;
    }
}

