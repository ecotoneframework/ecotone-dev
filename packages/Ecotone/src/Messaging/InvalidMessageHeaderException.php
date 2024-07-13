<?php

namespace Ecotone\Messaging;

/**
 * Class InvalidMessageHeaderException
 * @package Ecotone\Messaging\Exception
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
class InvalidMessageHeaderException extends MessagingException
{
    /**
     * @inheritDoc
     */
    protected static function errorCode(): int
    {
        return self::INVALID_MESSAGE_HEADER_EXCEPTION;
    }
}
