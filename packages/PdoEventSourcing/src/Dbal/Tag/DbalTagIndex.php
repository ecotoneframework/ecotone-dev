<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Dbal\Tag;

use function array_push;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Ecotone\EventSourcing\Database\TagTableManager;
use Ecotone\EventSourcing\Dbal\DbalEventStore;
use Ecotone\EventSourcing\Tagging\TagKey;
use Ecotone\Modelling\Event;

use function implode;

/**
 * licence Enterprise
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
     * @return array<array{stream: string, eventNo: int, has: array<string, bool>, sequences: array<string, ?int>}>
     */
    public function flagsFor(Connection $connection, array $tags): array
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

            $whereConditions[] = '(tag_name = ? AND tag_value = ?)';
            array_push($whereParameters, $tag['name'], $tag['value']);

            $position++;
        }

        $sql = 'SELECT stream_name, event_no, ' . implode(', ', $selectColumns)
            . " FROM {$indexTable} WHERE " . implode(' OR ', $whereConditions)
            . ' GROUP BY stream_name, event_no';

        $rows = TagConcurrencyGuard::run(
            fn () => $connection->executeQuery($sql, [...$selectParameters, ...$whereParameters])->fetchAllAssociative()
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
