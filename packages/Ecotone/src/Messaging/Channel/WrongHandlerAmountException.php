<?php

namespace Ecotone\Messaging\Channel;

use Ecotone\Messaging\MessagingException;

/**
 * Class WrongHandlerAmountException
 * @package Ecotone\Messaging\Channel
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
class WrongHandlerAmountException extends MessagingException
{
    /**
     * @inheritDoc
     */
    protected static function errorCode(): int
    {
        return self::WRONG_HANDLER_AMOUNT_EXCEPTION;
    }
}
