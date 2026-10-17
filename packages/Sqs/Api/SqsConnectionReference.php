<?php

declare(strict_types=1);

namespace Ecotone\Api\Sqs;

use Ecotone\Api\ExtensionObject\ConnectionReference;

/**
 * licence Apache-2.0
 */
final class SqsConnectionReference extends ConnectionReference
{
    public const DEFAULT = SqsConnectionFactory::class;

    public static function defaultConnection(): self
    {
        return new self(self::DEFAULT, null);
    }
}
