<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Recoverability;

use Ecotone\Api\Messaging\Message;

/**
 * licence Apache-2.0
 */
interface DeadLetterReplayOrder
{
    /**
     * @param list<string> $deadLetterIds
     * @return list<string>
     */
    public function replayableOf(array $deadLetterIds): array;

    public function releasing(string $deadLetterId, Message $message): Message;
}
