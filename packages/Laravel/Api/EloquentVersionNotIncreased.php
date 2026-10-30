<?php

declare(strict_types=1);

namespace Ecotone\Api\Laravel;

use Ecotone\Messaging\MessagingException;

/**
 * licence Apache-2.0
 */
final class EloquentVersionNotIncreased extends MessagingException
{
    public static function for(string $modelClass, string $aggregateId, int $loadedVersion, int $savedVersion): self
    {
        return self::create(sprintf(
            'Eloquent model %s %s was saved at version %d, but it was loaded at version %d. '
            . 'Its #[Version(autoIncrease: false)] property is moved by the application, and a save that does not increase it cannot be told apart from one that overwrites a concurrent save, so Ecotone refused it and the stored model is unchanged. '
            . 'Either increase the #[Version] property in the command handler, or remove autoIncrease: false so Ecotone increases it on every save.',
            $modelClass,
            $aggregateId,
            $savedVersion,
            $loadedVersion,
        ));
    }

    protected static function errorCode(): int
    {
        return self::MESSAGE_HANDLING_EXCEPTION;
    }
}
