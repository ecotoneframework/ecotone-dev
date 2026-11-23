<?php

namespace Ecotone\Messaging\Handler\Splitter;

use Ecotone\Api\Messaging\Message;

/**
 * Class DirectMessageSplitter
 * @package Ecotone\Messaging\Handler\Splitter
 * @author  Dariusz Gafka <support@simplycodedsoftware.com>
 * @internal
 */
/**
 * licence Apache-2.0
 */
class DirectMessageSplitter
{
    public function splitIterable(Message $message): array
    {
        $payload = $message->getPayload();
        return is_array($payload) ? array_values($payload) : iterator_to_array($payload, false);
    }

    /**
     * @param Message $message
     */
    public function split(Message $message): array
    {
        return $message->getPayload();
    }
}
