<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Recoverability;

use Ecotone\Api\Messaging\Message;

/**
 * licence Apache-2.0
 */
final class AnyDeadLetterReplayOrder implements DeadLetterReplayOrder
{
    public function replayableOf(array $deadLetterIds): array
    {
        return $deadLetterIds;
    }

    public function startOf(string $deadLetterId): string
    {
        return $deadLetterId;
    }

    public function releasing(string $deadLetterId, Message $message): Message
    {
        return $message;
    }

    public function ignoringPartitionOrder(string $deadLetterId, Message $message): Message
    {
        return $message;
    }
}
