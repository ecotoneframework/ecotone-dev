<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Scheduling;

use Ecotone\Messaging\MessagingException;

/**
 * Class SchedulingException
 * @package Ecotone\Messaging\Scheduling
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
class SchedulingException extends MessagingException
{
    /**
     * @inheritDoc
     */
    protected static function errorCode(): int
    {
        return 999;
    }
}
