<?php

namespace Test\Ecotone\Messaging\Fixture\Service\ServiceInterface;

use Ecotone\Messaging\Message;

/**
 * Interface ServiceInterface
 * @package Test\Ecotone\Messaging\Fixture\Service
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
/**
 * licence Apache-2.0
 */
interface ServiceReceivingMessageAndReturningMessage
{
    /**
     * @param Message $message
     *
     * @return Message
     */
    public function execute(Message $message): Message;
}
