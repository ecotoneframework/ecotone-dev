<?php

declare(strict_types=1);

namespace Ecotone\Api\Dbal\ExtensionObject;

use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;

/**
 * licence Apache-2.0
 */
enum TransactionReconnectProtection: string implements DefinedObject
{
    case REQUIRED = 'required';

    case WHEN_AVAILABLE = 'when_available';

    case IGNORE = 'ignore';

    public function getDefinition(): Definition
    {
        return new Definition(self::class, [$this->value], 'from');
    }
}
