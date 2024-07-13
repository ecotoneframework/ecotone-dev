<?php

namespace Ecotone\Messaging\Handler\Splitter;

use Ecotone\Messaging\Message;

/**
 * Class DirectMessageSplitter
 * @package Ecotone\Messaging\Handler\Splitter
 * @author  Dariusz Gafka <support@simplycodedsoftware.com>
 * @internal
 */
class DirectMessageSplitter
{
    /**
     * @param Message $message
     */
    public function split(Message $message): array
    {
        return $message->getPayload();
    }
}
