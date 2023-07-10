<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\Collector;

use Ecotone\Messaging\MessageChannel;
use Ecotone\Messaging\Message;

/**
 * This is responsible for collecting message in order to send them later.
 * This is useful in scenario where we given publisher is not transactional (e.g. SQS, Redis)
 * and we want to send the messages after database transaction is committed
 * or if we want to send messages in batch, instead of sending one by one.
 */
final class Collector
{
    /**
     * @param CollectedMessage[] $collectedMessages
     */
    public function __construct(private bool $enabled = false, private array $collectedMessages = []) {}

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function disable(): void
    {
        $this->collectedMessages = [];
        $this->enabled = false;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function send(string $collectedChannel, Message $message): void
    {
        $this->collectedMessages[] = new CollectedMessage($collectedChannel, $message);
    }

    /**
     * @return CollectedMessage[]
     */
    public function getCollectedMessages(): array
    {
        return $this->collectedMessages;
    }
}