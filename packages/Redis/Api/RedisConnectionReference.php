<?php

declare(strict_types=1);

namespace Ecotone\Api\Redis;

use Ecotone\Messaging\Config\ConnectionReference;
use Ecotone\Redis\Connection\RedisConnectionFactory;

/**
 * licence Apache-2.0
 */
final class RedisConnectionReference extends ConnectionReference
{
    public const DEFAULT = RedisConnectionFactory::class;

    public static function defaultConnection(): self
    {
        return new self(self::DEFAULT, null);
    }
}
