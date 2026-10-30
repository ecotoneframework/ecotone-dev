<?php

declare(strict_types=1);

namespace Ecotone\Api\Laravel;

use Ecotone\Messaging\MessagingException;

/**
 * licence Apache-2.0
 */
final class EloquentSaveCancelled extends MessagingException
{
    public static function for(string $modelClass, string $aggregateId): self
    {
        return self::create(sprintf(
            'Eloquent model %s %s was not saved: one of its saving, creating or updating listeners returned false, which cancels the save. '
            . 'Ecotone refuses it instead of reporting the command as handled with nothing stored, and rolled back the version it had moved, so the stored model is unchanged. '
            . 'Either let the listener throw an exception that names why the model must not be saved, or make that decision in the command handler before the aggregate changes.',
            $modelClass,
            $aggregateId,
        ));
    }

    protected static function errorCode(): int
    {
        return self::MESSAGE_HANDLING_EXCEPTION;
    }
}
