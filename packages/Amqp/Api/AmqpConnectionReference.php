<?php

declare(strict_types=1);

namespace Ecotone\Api\Amqp;

use Ecotone\Amqp\Connection\AmqpExtConnectionFactory;
use Ecotone\Amqp\Connection\AmqpLibConnectionFactory;
use Ecotone\Messaging\Config\ConnectionReference;

/**
 * licence Apache-2.0
 */
final class AmqpConnectionReference extends ConnectionReference
{
    public const DEFAULT = AmqpExtConnectionFactory::class;

    public const DEFAULT_STREAM = AmqpLibConnectionFactory::class;

    public static function defaultConnection(): self
    {
        return new self(self::DEFAULT, null);
    }

    public static function defaultStreamConnection(): self
    {
        return new self(self::DEFAULT_STREAM, null);
    }
}
