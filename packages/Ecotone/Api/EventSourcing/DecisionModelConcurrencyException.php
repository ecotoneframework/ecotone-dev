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

        return self::create($message);
    }
}
