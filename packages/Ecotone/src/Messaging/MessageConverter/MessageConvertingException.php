<?php

declare(strict_types=1);

namespace Ecotone\Messaging\MessageConverter;

use Ecotone\Messaging\MessagingException;

/**
 * Class MessageConvertingException
 * @package Ecotone\Messaging\MessageConverter
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
class MessageConvertingException extends MessagingException
{
    /**
     * @inheritDoc
     */
    protected static function errorCode(): int
    {
        return 500;
    }
}
