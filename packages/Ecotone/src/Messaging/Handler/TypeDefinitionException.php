<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler;

use Ecotone\Messaging\MessagingException;

/**
 * Class TypeDefinitionException
 * @package Ecotone\Messaging\Handler
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
class TypeDefinitionException extends MessagingException
{
    /**
     * @inheritDoc
     */
    protected static function errorCode(): int
    {
        return 150;
    }
}
