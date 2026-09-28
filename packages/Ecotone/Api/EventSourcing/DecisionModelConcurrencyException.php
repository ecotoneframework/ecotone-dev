<?php

declare(strict_types=1);

namespace Ecotone\Api\EventSourcing;

use Ecotone\Messaging\Support\ConcurrencyException;

/**
 * licence Enterprise
 */
class DecisionModelConcurrencyException extends ConcurrencyException
{
    public static function forConflict(string $tagName, string $tagValue, int $capturedVersion, int $currentVersion, ?string $modelClass = null): self
    {
        $message = sprintf(
            'Concurrent append conflict on tag %s:%s (expected version %d, current version %d)',
            $tagName,
            $tagValue,
            $capturedVersion,
            $currentVersion,
        );

        if ($modelClass !== null) {
            $message .= sprintf(' while deciding %s', $modelClass);
        }

        $message .= '. The tag moved after it was read: another transaction committed to it, or an earlier append in the same '
            . 'transaction did (for example a command sent from inside the handler whose own handler appends to the same tag) '
            . '-- the latter fails on every retry, so decide both in one handler instead.';

        return self::create($message);
    }

    public static function forAggregateConflict(string $aggregateType, string $aggregateId, int $capturedVersion, int $currentVersion): self
    {
        return self::create(sprintf(
            '%s %s changed since it was loaded (it was at change %d when read, it is at change %d now): another transaction saved it, '
            . 'or an earlier save in the same transaction did (for example a command sent from inside the handler whose own handler saves the same aggregate) '
            . '-- the latter fails on every retry, so decide both in one handler instead.',
            $aggregateType,
            $aggregateId,
            $capturedVersion,
            $currentVersion,
        ));
    }
}
