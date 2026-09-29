<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Dbal\Tag;

use function array_fill_keys;
use function array_keys;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Ecotone\Api\EventSourcing\DecisionModelConcurrencyException;
use Ecotone\EventSourcing\Database\TagTableManager;
use Ecotone\EventSourcing\Tagging\TagKey;

use function implode;

/**
 * licence Enterprise
 */
final class DbalTagVersionRegister
{
    /**
     * @param array<string, array{name: string, value: string}> $tags
     * @return array<string, array{name: string, value: string, expectedVersion: int}>
     */
    public function capture(Connection $connection, array $tags): array
    {
        $versions = $this->currentVersions($connection, $tags);

        $captured = [];
        foreach ($tags as $key => $tag) {
            $captured[$key] = ['name' => $tag['name'], 'value' => $tag['value'], 'expectedVersion' => $versions[$key]];
        }

        return $captured;
    }

    /**
     * @param array<string, array{name: string, value: string}> $tags
     * @return array<string, int>
     */
    public function currentVersions(Connection $connection, array $tags): array
    {
        $versions = array_fill_keys(array_keys($tags), 0);
        if ($tags === []) {
            return $versions;
        }

        $conditions = [];
        $parameters = [];
        foreach ($tags as $tag) {
            $conditions[] = '(tag_name = ? AND tag_value = ?)';
            $parameters[] = $tag['name'];
            $parameters[] = $tag['value'];
        }

        $versionsTable = TaggedEventSchemaFactory::for($connection)->quoteIdentifier(TagTableManager::TAG_VERSIONS_TABLE);
        $rows = TagConcurrencyGuard::run(
            fn () => $connection->executeQuery("SELECT tag_name, tag_value, version FROM {$versionsTable} WHERE " . implode(' OR ', $conditions), $parameters)->fetchAllAssociative()
        );

        foreach ($rows as $row) {
            $key = TagKey::of($row['tag_name'], $row['tag_value']);
            if (isset($versions[$key])) {
                $versions[$key] = (int) $row['version'];
            }
        }

        return $versions;
    }

    /**
     * @param array<string, array{name: string, value: string, expectedVersion: int, aggregateType?: string, decidedBy?: string[]}> $expectedVersions
     */
    public function bumpGuarded(Connection $connection, array $expectedVersions): void
    {
        foreach ($expectedVersions as $expected) {
            $this->bumpGuardedTag($connection, $expected['name'], $expected['value'], $expected['expectedVersion'], $expected['aggregateType'] ?? null, $expected['decidedBy'] ?? []);
        }
    }

    /**
     * @param array<string, array{name: string, value: string}> $tags
     */
    public function bumpForBackfill(Connection $connection, array $tags): void
    {
        $sql = TaggedEventSchemaFactory::for($connection)->upsertIncrementVersionSql(TagTableManager::TAG_VERSIONS_TABLE);

        foreach ($tags as $tag) {
            TagConcurrencyGuard::run(fn () => $connection->executeStatement($sql, [$tag['name'], $tag['value']]));
        }
    }

    /**
     * @param string[] $decidedBy
     */
    private function bumpGuardedTag(Connection $connection, string $name, string $value, int $capturedVersion, ?string $aggregateType, array $decidedBy): void
    {
        $tagSchema = TaggedEventSchemaFactory::for($connection);
        $versionsTable = $tagSchema->quoteIdentifier(TagTableManager::TAG_VERSIONS_TABLE);

        if ($capturedVersion === 0) {
            try {
                $affected = (int) TagConcurrencyGuard::run(fn () => $connection->executeStatement($tagSchema->insertInitialVersionSql(TagTableManager::TAG_VERSIONS_TABLE), [$name, $value]));
            } catch (UniqueConstraintViolationException) {
                $affected = 0;
            }
        } else {
            $affected = (int) TagConcurrencyGuard::run(fn () => $connection->executeStatement(
                "UPDATE {$versionsTable} SET version = version + 1 WHERE tag_name = ? AND tag_value = ? AND version = ?",
                [$name, $value, $capturedVersion]
            ));
        }

        if ($affected === 0) {
            throw $aggregateType !== null
                ? DecisionModelConcurrencyException::forAggregateConflict($aggregateType, $value, $capturedVersion, $this->versionOf($connection, $name, $value))
                : DecisionModelConcurrencyException::forConflict($name, $value, $capturedVersion, $this->versionOf($connection, $name, $value), $decidedBy);
        }
    }

    private function versionOf(Connection $connection, string $name, string $value): int
    {
        $versionsTable = TaggedEventSchemaFactory::for($connection)->quoteIdentifier(TagTableManager::TAG_VERSIONS_TABLE);

        return (int) $connection->executeQuery(
            "SELECT version FROM {$versionsTable} WHERE tag_name = ? AND tag_value = ?",
            [$name, $value]
        )->fetchOne();
    }
}
