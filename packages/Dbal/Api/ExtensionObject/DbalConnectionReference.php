<?php

declare(strict_types=1);

namespace Ecotone\Api\Dbal\ExtensionObject;

use Ecotone\Api\ExtensionObject\ConnectionReference;
use Ecotone\Dbal\Connection\DbalConnectionFactory;

/**
 * licence Apache-2.0
 */
final class DbalConnectionReference extends ConnectionReference
{
    public const DEFAULT = DbalConnectionFactory::class;

    public static function defaultConnection(): self
    {
        return new self(self::DEFAULT, null);
    }
}
