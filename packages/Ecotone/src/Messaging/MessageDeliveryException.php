<?php

namespace Ecotone\Messaging;

/**
 * Class MessageSendException
 * @package Ecotone\Messaging\Exception
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
class MessageDeliveryException extends MessagingException
{
    /**
     * @inheritDoc
     */
    protected static function errorCode(): int
    {
        return self::MESSAGE_DELIVERY_EXCEPTION;
    }
}
