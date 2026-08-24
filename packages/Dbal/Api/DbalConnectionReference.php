<?php

declare(strict_types=1);

namespace Ecotone\Api\Dbal;

use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Messaging\Config\ConnectionReference;

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
