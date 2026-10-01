<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Support;

use Ecotone\Messaging\MessagingException;

use function sprintf;

/**
 * licence Apache-2.0
 */
class ConcurrencyException extends MessagingException
{
    public static function forStaleAggregate(string $aggregateType, string $aggregateId, int $expectedVersion, int $currentVersion): static
    {
        return static::create(sprintf(
            'Aggregate %s %s was loaded at version %d, but it is at version %d now: another transaction saved it after it was loaded, '
            . 'or an earlier save in the same transaction did (for example a command sent from inside the handler whose own handler saves the same aggregate). '
            . 'Load it again and retry the command against its current version: send the command again, '
            . 'let Ecotone retry it with InstantRetryConfiguration::createWithDefaults()->withCommandBusRetry(true, 3, [ConcurrencyException::class]), '
            . 'or rely on an asynchronous endpoint\'s default retries. A save made earlier in the same transaction fails on every retry, so decide both in one handler instead.',
            $aggregateType,
            $aggregateId,
            $expectedVersion,
            $currentVersion,
        ));
    }

    protected static function errorCode(): int
    {
        return self::MESSAGE_HANDLING_EXCEPTION;
    }
}
