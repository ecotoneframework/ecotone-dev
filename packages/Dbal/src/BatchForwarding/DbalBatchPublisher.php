<?php

declare(strict_types=1);

namespace Ecotone\Dbal\BatchForwarding;

/**
 * licence Enterprise
 */
final class DbalBatchPublisher
{
    private const IDLE_POLL_INTERVAL_IN_MILLISECONDS = 100;

    private bool $previousPublishFoundNothing = false;

    /**
     * @param DbalOutboxPublisher[] $outboxPublishers
     */
    public function __construct(private array $outboxPublishers)
    {
    }

    public function publish(): ?int
    {
        $publishedAmount = 0;
        foreach ($this->outboxPublishers as $outboxPublisher) {
            $publishedAmount += $outboxPublisher->publishBatch();
        }

        if ($publishedAmount > 0) {
            $this->previousPublishFoundNothing = false;

            return $publishedAmount;
        }

        if ($this->previousPublishFoundNothing) {
            usleep(self::IDLE_POLL_INTERVAL_IN_MILLISECONDS * 1000);
        }
        $this->previousPublishFoundNothing = true;

        return null;
    }
}
