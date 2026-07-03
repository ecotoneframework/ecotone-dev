<?php

declare(strict_types=1);

namespace Ecotone\Amqp;

/**
 * licence Enterprise
 */
final class AmqpExtPublisherConfirmations
{
    private int $publishedCount = 0;

    private int $epoch = 0;

    private int $highestConfirmedTag = 0;

    /** @var array<int, bool> */
    private array $individuallyConfirmedTags = [];

    public function recordPublishedMessage(): void
    {
        $this->publishedCount++;
    }

    public function recordConfirmation(int $deliveryTag, bool $multiple): void
    {
        if ($multiple) {
            $this->highestConfirmedTag = max($this->highestConfirmedTag, $deliveryTag);
            foreach ($this->individuallyConfirmedTags as $tag => $confirmed) {
                if ($tag <= $this->highestConfirmedTag) {
                    unset($this->individuallyConfirmedTags[$tag]);
                }
            }

            return;
        }

        if ($deliveryTag > $this->highestConfirmedTag) {
            $this->individuallyConfirmedTags[$deliveryTag] = true;
        }
    }

    public function hasOutstandingConfirmations(): bool
    {
        return $this->publishedCount > ($this->highestConfirmedTag + count($this->individuallyConfirmedTags));
    }

    public function reset(): void
    {
        $this->epoch++;
        $this->publishedCount = 0;
        $this->highestConfirmedTag = 0;
        $this->individuallyConfirmedTags = [];
    }

    public function getEpoch(): int
    {
        return $this->epoch;
    }
}
