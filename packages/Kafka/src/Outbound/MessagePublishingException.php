<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Outbound;

use Ecotone\Messaging\MessagingException;

/**
 * licence Enterprise
 */
final class MessagePublishingException extends MessagingException
{
    protected static function errorCode(): int
    {
        return self::MESSAGE_PUBLISH_EXCEPTION;
    }
}
