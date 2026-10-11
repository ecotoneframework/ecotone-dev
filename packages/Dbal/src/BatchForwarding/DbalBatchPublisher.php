<?php

declare(strict_types=1);

namespace Ecotone\Dbal\BatchForwarding;

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
