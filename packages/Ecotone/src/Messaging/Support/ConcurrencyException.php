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
            'Aggregate %s %s was loaded at version %d, but %s: another transaction saved it after it was loaded, '
            . 'or an earlier save in the same transaction did (for example a command sent from inside the handler whose own handler saves the same aggregate). '
            . 'Load it again and retry the command against its current version: send the command again, '
            . 'let Ecotone retry it with InstantRetryConfiguration::createWithDefaults()->withCommandBusRetry(true, 3, [ConcurrencyException::class]), '
            . 'or rely on an asynchronous endpoint\'s default retries. A save made earlier in the same transaction fails on every retry, so decide both in one handler instead.',
            $aggregateType,
            $aggregateId,
            $expectedVersion,
            self::whereItIsNow($expectedVersion, $currentVersion),
        ));
    }

    public static function forStaleDocument(string $collectionName, string $documentId, int $expectedVersion, int $currentVersion): static
    {
        return static::create(sprintf(
            'Document %s in collection %s was expected at version %d, but %s (version 0 means it is not stored): '
            . 'another write changed it after it was read. Read it again with DocumentStore::getDocument() and DocumentStore::getDocumentVersion(), '
            . 'apply the change to what is stored now and write it under that version, '
            . 'or pass DocumentStore::LAST_WRITE_WINS as the expected version when this write should replace whatever is stored.',
            $documentId,
            $collectionName,
            $expectedVersion,
            self::whereItIsNow($expectedVersion, $currentVersion),
        ));
    }

    private static function whereItIsNow(int $expectedVersion, int $currentVersion): string
    {
        return $currentVersion === $expectedVersion
            ? 'it has been saved since and this transaction still reads the earlier version (a REPEATABLE READ snapshot, as on MySQL and MariaDB)'
            : sprintf('it is at version %d now', $currentVersion);
    }

    protected static function errorCode(): int
    {
        return self::MESSAGE_HANDLING_EXCEPTION;
    }
}
