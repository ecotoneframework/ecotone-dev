<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Dbal\Tag;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverExceptionInterface;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Ecotone\Api\EventSourcing\DecisionModelConcurrencyException;
use Ecotone\Dbal\Database\AutomaticTableInitializationSupport;
use Ecotone\Dbal\Database\MissingTableInstructions;
use Ecotone\EventSourcing\Database\TagTableManager;
use Ecotone\EventSourcing\Dbal\DbalEventStore;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Support\ConcurrencyException;

use function implode;

/**
 * licence Enterprise
 */
final class DbalTagVersionRegister
{
    /** @var array<string, bool> */
    private array $ensuredTagTables = [];

    public function tagKey(string $name, string $value): string
    {
        return $name . "\0" . $value;
    }

    public function ensureTagTablesExist(DbalEventStore $eventStore, Connection $connection, string $streamName): void
    {
        $contextKey = $eventStore->tagTableContextKeyFor($streamName);
        if (isset($this->ensuredTagTables[$contextKey])) {
            return;
        }

        $tagSchema = TaggedEventSchemaFactory::for($connection);
        if ($tagSchema->tableExists($connection, TagTableManager::TAGGED_EVENTS_TABLE)
            && $tagSchema->tableExists($connection, TagTableManager::TAG_VERSIONS_TABLE)) {
            $this->ensuredTagTables[$contextKey] = true;

            return;
        }

        $isAutomaticInitializationSupported = AutomaticTableInitializationSupport::isSupported($connection);
        if (! $eventStore->isAutomaticTableInitializationEnabled() || ! $isAutomaticInitializationSupported) {
            $tableNames = TagTableManager::TAGGED_EVENTS_TABLE . ', ' . TagTableManager::TAG_VERSIONS_TABLE;

            throw ConfigurationException::create(
                $isAutomaticInitializationSupported
                    ? MissingTableInstructions::build(TagTableManager::FEATURE_NAME, $tableNames, $eventStore->consoleInvocationPrefix())
                    : MissingTableInstructions::buildForUnsupportedAutomaticInitialization(TagTableManager::FEATURE_NAME, $tableNames, $eventStore->consoleInvocationPrefix())
            );
        }

        foreach ([
            ...$tagSchema->createTaggedEventsTableSql(TagTableManager::TAGGED_EVENTS_TABLE),
            ...$tagSchema->createTagVersionsTableSql(TagTableManager::TAG_VERSIONS_TABLE),
        ] as $statement) {
            $connection->executeStatement($statement);
        }

        $this->ensuredTagTables[$contextKey] = true;
    }

    /**
     * @param array<string, array{name: string, value: string}> $allTags
     * @return array<string, array{name: string, value: string, expectedVersion: int}>
     */
    public function captureTagVersions(Connection $connection, TaggedEventSchema $tagSchema, array $allTags): array
    {
        if ($allTags === []) {
            return [];
        }

        $versionsTable = $tagSchema->quoteIdentifier(TagTableManager::TAG_VERSIONS_TABLE);

        $conditions = [];
        $parameters = [];
        foreach ($allTags as $tag) {
            $conditions[] = '(tag_name = ? AND tag_value = ?)';
            $parameters[] = $tag['name'];
            $parameters[] = $tag['value'];
        }

        $rows = $this->runGuarded(
            fn () => $connection->executeQuery("SELECT tag_name, tag_value, version FROM {$versionsTable} WHERE " . implode(' OR ', $conditions), $parameters)->fetchAllAssociative()
        );

        $captured = [];
        foreach ($allTags as $key => $tag) {
            $captured[$key] = ['name' => $tag['name'], 'value' => $tag['value'], 'expectedVersion' => 0];
        }

        foreach ($rows as $row) {
            $key = $this->tagKey($row['tag_name'], $row['tag_value']);
            if (! isset($captured[$key])) {
                continue;
            }

            $captured[$key]['expectedVersion'] = (int) $row['version'];
        }

        return $captured;
    }

    public function bumpGuardedTagVersion(Connection $connection, TaggedEventSchema $tagSchema, string $name, string $value, int $capturedVersion): int
    {
        $versionsTable = $tagSchema->quoteIdentifier(TagTableManager::TAG_VERSIONS_TABLE);
        if ($capturedVersion === 0) {
            try {
                $affected = (int) $this->runGuarded(fn () => $connection->executeStatement($tagSchema->insertInitialVersionSql(TagTableManager::TAG_VERSIONS_TABLE), [$name, $value]));
            } catch (UniqueConstraintViolationException) {
                $affected = 0;
            }

            if ($affected === 0) {
                throw DecisionModelConcurrencyException::forConflict($name, $value, $capturedVersion, $this->currentTagVersion($connection, $tagSchema, $name, $value));
            }

            return 1;
        }

        $affected = (int) $this->runGuarded(fn () => $connection->executeStatement(
            "UPDATE {$versionsTable} SET version = version + 1 WHERE tag_name = ? AND tag_value = ? AND version = ?",
            [$name, $value, $capturedVersion]
        ));

        if ($affected === 0) {
            throw DecisionModelConcurrencyException::forConflict($name, $value, $capturedVersion, $this->currentTagVersion($connection, $tagSchema, $name, $value));
        }

        return $capturedVersion + 1;
    }

    public function bumpTagVersionForBackfill(Connection $connection, TaggedEventSchema $tagSchema, string $name, string $value): int
    {
        $sql = $tagSchema->upsertIncrementVersionSql(TagTableManager::TAG_VERSIONS_TABLE);

        $newVersion = $tagSchema->supportsReturningOnUpsert()
            ? (int) $this->runGuarded(fn () => $connection->executeQuery($sql, [$name, $value])->fetchOne())
            : (function () use ($connection, $tagSchema, $sql, $name, $value): int {
                $this->runGuarded(fn () => $connection->executeStatement($sql, [$name, $value]));

                return $this->currentTagVersion($connection, $tagSchema, $name, $value);
            })();

        return $newVersion;
    }

    public function currentTagVersion(Connection $connection, TaggedEventSchema $tagSchema, string $name, string $value): int
    {
        $versionsTable = $tagSchema->quoteIdentifier(TagTableManager::TAG_VERSIONS_TABLE);

        return (int) $connection->executeQuery(
            "SELECT version FROM {$versionsTable} WHERE tag_name = ? AND tag_value = ?",
            [$name, $value]
        )->fetchOne();
    }

    public function runGuarded(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (RetryableException $exception) {
            throw new ConcurrencyException($exception->getMessage(), 0, $exception);
        } catch (DriverExceptionInterface $exception) {
            // MariaDB 1020 (innodb_snapshot_isolation "Record has changed since last read")
            // and PostgreSQL 55P03 (lock_not_available, from SET lock_timeout) are not mapped
            // to a typed DBAL exception -- both mean "someone else is holding this row".
            if ($exception->getCode() === 1020 || $exception->getSQLState() === '55P03') {
                throw new ConcurrencyException($exception->getMessage(), 0, $exception);
            }

            throw $exception;
        }
    }
}
