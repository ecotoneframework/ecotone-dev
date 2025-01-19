<?php

declare(strict_types=1);

namespace Ecotone\Modelling\MessageHandling\Distribution;

use Ecotone\Messaging\MessagingException;

final class UnknownDistributedDestination extends MessagingException
{
    protected static function errorCode(): int
    {
        return self::DISTRIBUTED_DESTINATION_NOT_FOUND;
    }
}