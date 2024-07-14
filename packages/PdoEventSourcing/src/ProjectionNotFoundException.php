<?php

namespace Ecotone\EventSourcing;

use Ecotone\Messaging\MessagingException;

/**
 * licence Apache-2.0
 */
final class ProjectionNotFoundException extends MessagingException
{
    protected static function errorCode(): int
    {
        return 3001;
    }
}
