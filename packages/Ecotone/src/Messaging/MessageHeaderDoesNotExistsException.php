<?php

namespace Ecotone\Messaging;

/**
 * Class MessageHeaderDoesNotExistsException
 * @package Ecotone\Messaging\Exception
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
class MessageHeaderDoesNotExistsException extends MessagingException
{
    /**
     * @inheritDoc
     */
    protected static function errorCode(): int
    {
        return MessagingException::MESSAGE_HEADER_NOT_AVAILABLE_EXCEPTION;
    }
}
