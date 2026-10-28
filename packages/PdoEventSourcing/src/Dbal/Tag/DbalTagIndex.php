<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Dbal\Tag;

use function array_map;
use function array_push;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Ecotone\Api\EventSourcing\Event;
use Ecotone\EventSourcing\Database\TagTableManager;
use Ecotone\EventSourcing\Dbal\DbalEventStore;
use Ecotone\EventSourcing\Tagging\TagKey;

use function implode;

use const PHP_INT_MAX;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
final class DbalTagIndex
{
    /**
     * @param string[] $eventIds
     * @param array<int, array<array{name: string, value: string, sequence: int}>> $sequencedTagsPerEvent
     */
    public function insertRows(Connection $connection, string $tableName, array $eventIds, array $sequencedTagsPerEvent): void
    {
        $tagSchema = TaggedEventSchemaFactory::for($connection);

        $selects = [];
        $parameters = [];
        $types = [];
        foreach ($eventIds as $i => $eventId) {
            foreach ($sequencedTagsPerEvent[$i] as $tag) {
                $selects[] = 'SELECT ? AS tag_name, ? AS tag_value, ? AS stream_name, s.no AS event_no, ' . $tagSchema->bigIntPlaceholder() . ' AS tag_sequence FROM '
                    . $tagSchema->quoteIdentifier($tableName) . ' s WHERE s.event_id = ?';
                array_push($parameters, $tag['name'], $tag['value'], $tableName, $tag['sequence'], $eventId);
                array_push($types, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER, ParameterType::STRING);
            }
        }

        if ($selects === []) {
            return;
        }

        $indexTable = $tagSchema->quoteIdentifier(TagTableManager::TAGGED_EVENTS_TABLE);

        $connection->executeStatement(
            "INSERT INTO {$indexTable} (tag_name, tag_value, stream_name, event_no, tag_sequence) " . implode(' UNION ALL ', $selects),
            $parameters,
            $types
        );
    }

    /**
     * @param string[] $eventIds
     * @param array<int, array<array{name: string, value: string, sequence: int}>> $sequencedTagsPerEvent
     */
    public function insertRowsIdempotently(Connection $connection, string $tableName, array $eventIds, array $sequencedTagsPerEvent): void
    {
        $sql = TaggedEventSchemaFactory::for($connection)->idempotentInsertIndexRowSql(TagTableManager::TAGGED_EVENTS_TABLE, $tableName);

        foreach ($eventIds as $i => $eventId) {
            foreach ($sequencedTagsPerEvent[$i] as $tag) {
                $connection->executeStatement($sql, [$tag['name'], $tag['value'], $tableName, $tag['sequence'], $eventId]);
            }
        }
    }

    /**
     * @param array<string, array{name: string, value: string}> $tags
     * @param array<string, int> $tagSequenceLowerBounds
     * @return array<array{stream: string, eventNo: int, has: array<string, bool>, sequences: array<string, ?int>}>
     */
    public function flagsFor(Connection $connection, array $tags, array $tagSequenceLowerBounds = []): array
    {
        return $this->flagsMatching($connection, $tags, $tagSequenceLowerBounds, '', []);
    }

    public function countValuesOfTag(Connection $connection, string $tagName): int
    {
        $indexTable = TaggedEventSchemaFactory::for($connection)->quoteIdentifier(TagTableManager::TAGGED_EVENTS_TABLE);

        return (int) $connection->executeQuery("SELECT COUNT(DISTINCT tag_value) FROM {$indexTable} WHERE tag_name = ?", [$tagName])->fetchOne();
    }

    /**
     * @return string[]
     */
    public function valuesOfTag(Connection $connection, string $tagName, ?int $limit, int $offset): array
    {
        $indexTable = TaggedEventSchemaFactory::for($connection)->quoteIdentifier(TagTableManager::TAGGED_EVENTS_TABLE);
        $limitClause = $limit === null ? '' : " LIMIT {$limit}";
        $offsetClause = $offset > 0 ? " OFFSET {$offset}" : '';
        if ($limit === null && $offset > 0) {
            $limitClause = ' LIMIT ' . PHP_INT_MAX;
        }

        return array_map('strval', $connection->executeQuery(
            "SELECT DISTINCT tag_value FROM {$indexTable} WHERE tag_name = ? ORDER BY tag_value{$limitClause}{$offsetClause}",
            [$tagName],
        )->fetchFirstColumn());
    }

    /**
     * @param array{name: string, value: string} $tag
     * @return int[]
     */
    public function sequencesOfTagAfter(Connection $connection, array $tag, int $afterTagSequence, int $limit): array
    {
        $indexTable = TaggedEventSchemaFactory::for($connection)->quoteIdentifier(TagTableManager::TAGGED_EVENTS_TABLE);

        return array_map('intval', TagConcurrencyGuard::run(
            fn () => $connection->executeQuery(
                "SELECT DISTINCT tag_sequence FROM {$indexTable} WHERE tag_name = ? AND tag_value = ? AND tag_sequence > ? ORDER BY tag_sequence LIMIT {$limit}",
                [$tag['name'], $tag['value'], $afterTagSequence],
            )->fetchFirstColumn()
        ));
    }

    /**
     * @param array<string, array{name: string, value: string}> $tags
     * @return array<array{stream: string, eventNo: int, has: array<string, bool>, sequences: array<string, ?int>}>
     */
    public function flagsWithinSequences(Connection $connection, array $tags, string $positionTagKey, int $afterTagSequence, int $upToTagSequence): array
    {
        $positionTag = $tags[$positionTagKey];

        return $this->flagsMatching(
            $connection,
            $tags,
            [$positionTagKey => $afterTagSequence],
            ' HAVING MAX(CASE WHEN tag_name = ? AND tag_value = ? THEN tag_sequence END) <= ?',
            [$positionTag['name'], $positionTag['value'], $upToTagSequence],
        );
    }

    /**
     * @param array<string, array{name: string, value: string}> $tags
     * @param array<string, int> $tagSequenceLowerBounds
     * @param array<string|int> $groupParameters
     * @return array<array{stream: string, eventNo: int, has: array<string, bool>, sequences: array<string, ?int>}>
     */
    private function flagsMatching(Connection $connection, array $tags, array $tagSequenceLowerBounds, string $afterGrouping, array $groupParameters): array
    {
        $indexTable = TaggedEventSchemaFactory::for($connection)->quoteIdentifier(TagTableManager::TAGGED_EVENTS_TABLE);

        $selectColumns = [];
        $selectParameters = [];
        $whereConditions = [];
        $whereParameters = [];
        $tagPositions = [];

        $position = 0;
        foreach ($tags as $key => $tag) {
            $tagPositions[$key] = $position;

            $selectColumns[] = "MAX(CASE WHEN tag_name = ? AND tag_value = ? THEN tag_sequence END) AS sequence_{$position}";
            $selectColumns[] = "MAX(CASE WHEN tag_name = ? AND tag_value = ? THEN 1 ELSE 0 END) AS has_{$position}";
            array_push($selectParameters, $tag['name'], $tag['value'], $tag['name'], $tag['value']);

            $lowerBound = $tagSequenceLowerBounds[$key] ?? null;
            $whereConditions[] = $lowerBound === null
                ? '(tag_name = ? AND tag_value = ?)'
                : '(tag_name = ? AND tag_value = ? AND tag_sequence > ?)';
            array_push($whereParameters, $tag['name'], $tag['value']);
            if ($lowerBound !== null) {
                $whereParameters[] = $lowerBound;
            }

            $position++;
        }

        $sql = 'SELECT stream_name, event_no, ' . implode(', ', $selectColumns)
            . " FROM {$indexTable} WHERE " . implode(' OR ', $whereConditions)
            . ' GROUP BY stream_name, event_no' . $afterGrouping;

        $rows = TagConcurrencyGuard::run(
            fn () => $connection->executeQuery($sql, [...$selectParameters, ...$whereParameters, ...$groupParameters])->fetchAllAssociative()
        );

        $flags = [];
        foreach ($rows as $row) {
            $has = [];
            $sequences = [];
            foreach ($tagPositions as $key => $tagPosition) {
                $has[$key] = ((int) $row["has_{$tagPosition}"]) === 1;
                $sequences[$key] = $row["sequence_{$tagPosition}"] !== null ? (int) $row["sequence_{$tagPosition}"] : null;
            }

            $flags[] = ['stream' => $row['stream_name'], 'eventNo' => (int) $row['event_no'], 'has' => $has, 'sequences' => $sequences];
        }

        return $flags;
    }

    /**
     * @param array<array{stream: string, eventNo: int}> $flags
     * @return array<string, array<int, Event>>
     */
    public function eventsReferencedBy(DbalEventStore $eventStore, Connection $connection, array $flags): array
    {
        $eventNosByStream = [];
        foreach ($flags as $flag) {
            $eventNosByStream[$flag['stream']][] = $flag['eventNo'];
        }

        $eventsByStream = [];
        foreach ($eventNosByStream as $streamTable => $eventNos) {
            $eventsByStream[$streamTable] = TagConcurrencyGuard::run(
                fn () => $eventStore->loadEventsByNumbers($connection, $streamTable, $eventNos)
            );
        }

        return $eventsByStream;
    }

    /**
     * @return array<string, true>
     */
    public function indexedTagKeysOfEvent(Connection $connection, string $tableName, string $eventId): array
    {
        $tagSchema = TaggedEventSchemaFactory::for($connection);
        $indexTable = $tagSchema->quoteIdentifier(TagTableManager::TAGGED_EVENTS_TABLE);

        $rows = $connection->executeQuery(
            "SELECT tag_name, tag_value FROM {$indexTable} WHERE stream_name = ? AND event_no = (SELECT no FROM " . $tagSchema->quoteIdentifier($tableName) . ' WHERE event_id = ?)',
            [$tableName, $eventId]
        )->fetchAllAssociative();

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[TagKey::of($row['tag_name'], $row['tag_value'])] = true;
        }

        return $indexed;
    }

    public function deleteForStream(Connection $connection, string $tableName): void
    {
        $tagSchema = TaggedEventSchemaFactory::for($connection);
        if (! $tagSchema->tableExists($connection, TagTableManager::TAGGED_EVENTS_TABLE)) {
            return;
        }

        $connection->executeStatement(
            'DELETE FROM ' . $tagSchema->quoteIdentifier(TagTableManager::TAGGED_EVENTS_TABLE) . ' WHERE stream_name = ?',
            [$tableName]
        );
    }
}
