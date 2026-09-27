<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Dbal\Tag;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Ecotone\Api\EventSourcing\AppendCondition;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\EventSourcing\LoadedEvents;
use Ecotone\EventSourcing\Dbal\EventStreamSchemaFactory;
use Ecotone\EventSourcing\Database\TagTableManager;
use Ecotone\EventSourcing\StreamTableRegistry;
use Ecotone\Modelling\Event;

use function array_fill;
use function array_map;
use function array_values;
use function count;
use function implode;
use function uasort;

/**
 * licence Enterprise
 */
final class DbalTaggedEventReader
{
    public function __construct(
        private readonly DbalTagVersionRegister $versionRegister,
    ) {
    }

    public function loadByCriteria(DbalEventRowAccess $rowAccess, Connection $connection, EventCriteria $criteria): LoadedEvents
    {
        $branches = $criteria->branches();

        $this->versionRegister->resetOwnBumpTrackingIfNoTransaction($connection);
        $tagSchema = TaggedEventSchemaFactory::for($connection);

        $allTags = [];
        foreach ($branches as $criterion) {
            foreach ($criterion->tags() as $tag) {
                $allTags[$this->versionRegister->tagKey($tag['name'], $tag['value'])] = $tag;
            }
        }

        if ($allTags === []) {
            return new LoadedEvents([], AppendCondition::empty());
        }

        $this->versionRegister->ensureTagTablesExist($rowAccess, $connection, StreamTableRegistry::DEFAULT_STREAM);

        $capturedTags = $this->versionRegister->captureTagVersions($connection, $tagSchema, $allTags);
        $flags = $this->fetchTagFlags($connection, $tagSchema, $allTags);

        if ($flags === []) {
            return new LoadedEvents([], AppendCondition::fromCapturedVersions(array_values($capturedTags)));
        }

        $eventsByStream = $this->fetchCandidateEvents($rowAccess, $connection, $flags);

        $matched = [];
        foreach ($branches as $criterion) {
            $tags = $criterion->tags();
            if ($tags === []) {
                continue;
            }

            $primaryKey = $this->versionRegister->tagKey($tags[0]['name'], $tags[0]['value']);

            foreach ($flags as $refKey => $flag) {
                $matchesAllTags = true;
                foreach ($tags as $tag) {
                    if (empty($flag['has'][$this->versionRegister->tagKey($tag['name'], $tag['value'])])) {
                        $matchesAllTags = false;

                        break;
                    }
                }

                if (! $matchesAllTags) {
                    continue;
                }

                $event = $eventsByStream[$flag['stream']][$flag['eventNo']] ?? null;
                if ($event === null || ! $criterion->matchesEventType($event->getEventName())) {
                    continue;
                }

                $tagVersion = $flag['seq'][$primaryKey] ?? null;
                if ($tagVersion === null) {
                    continue;
                }

                if (! isset($matched[$refKey]) || $matched[$refKey]['tagVersion'] > $tagVersion) {
                    $matched[$refKey] = ['tagVersion' => $tagVersion, 'eventNo' => $flag['eventNo'], 'event' => $event];
                }
            }
        }

        uasort($matched, static fn (array $a, array $b): int => $a['tagVersion'] <=> $b['tagVersion'] ?: $a['eventNo'] <=> $b['eventNo']);

        $events = array_values(array_map(static fn (array $match) => $match['event'], $matched));

        return new LoadedEvents($events, AppendCondition::fromCapturedVersions(array_values($capturedTags)));
    }

    /**
     * @param array<string, array{name: string, value: string}> $allTags
     * @return array<string, array{stream: string, eventNo: int, has: array<string, bool>, seq: array<string, ?int>}>
     */
    private function fetchTagFlags(Connection $connection, TaggedEventSchema $tagSchema, array $allTags): array
    {
        $indexTable = $tagSchema->quoteIdentifier(TagTableManager::TAGGED_EVENTS_TABLE);

        $selectColumns = [];
        $selectParameters = [];
        $whereConditions = [];
        $whereParameters = [];
        $tagIndexes = [];

        $i = 0;
        foreach ($allTags as $key => $tag) {
            $tagIndexes[$key] = $i;

            $selectColumns[] = "MAX(CASE WHEN tag_name = ? AND tag_value = ? THEN tag_sequence END) AS seq_{$i}";
            $selectParameters[] = $tag['name'];
            $selectParameters[] = $tag['value'];

            $selectColumns[] = "MAX(CASE WHEN tag_name = ? AND tag_value = ? THEN 1 ELSE 0 END) AS has_{$i}";
            $selectParameters[] = $tag['name'];
            $selectParameters[] = $tag['value'];

            $whereConditions[] = '(tag_name = ? AND tag_value = ?)';
            $whereParameters[] = $tag['name'];
            $whereParameters[] = $tag['value'];

            $i++;
        }

        $sql = 'SELECT stream_name, event_no, ' . implode(', ', $selectColumns)
            . " FROM {$indexTable} WHERE " . implode(' OR ', $whereConditions)
            . ' GROUP BY stream_name, event_no';

        $rows = $this->versionRegister->runGuarded(
            fn () => $connection->executeQuery($sql, [...$selectParameters, ...$whereParameters])->fetchAllAssociative()
        );

        $flags = [];
        foreach ($rows as $row) {
            $refKey = $row['stream_name'] . "\0" . $row['event_no'];
            $has = [];
            $seq = [];
            foreach ($tagIndexes as $key => $idx) {
                $has[$key] = ((int) $row["has_{$idx}"]) === 1;
                $seq[$key] = $row["seq_{$idx}"] !== null ? (int) $row["seq_{$idx}"] : null;
            }

            $flags[$refKey] = [
                'stream' => $row['stream_name'],
                'eventNo' => (int) $row['event_no'],
                'has' => $has,
                'seq' => $seq,
            ];
        }

        return $flags;
    }

    /**
     * @param array<string, array{stream: string, eventNo: int}> $flags
     * @return array<string, array<int, Event>>
     */
    private function fetchCandidateEvents(DbalEventRowAccess $rowAccess, Connection $connection, array $flags): array
    {
        $eventNosByStream = [];
        foreach ($flags as $flag) {
            $eventNosByStream[$flag['stream']][] = $flag['eventNo'];
        }

        $schema = EventStreamSchemaFactory::for($connection);
        $eventsByStream = [];
        foreach ($eventNosByStream as $streamTable => $eventNos) {
            $placeholders = implode(', ', array_fill(0, count($eventNos), '?'));
            $types = array_fill(0, count($eventNos), ParameterType::INTEGER);

            $rows = $this->versionRegister->runGuarded(
                fn () => $connection->executeQuery(
                    'SELECT no, event_name, payload, metadata FROM ' . $schema->quoteIdentifier($streamTable) . " WHERE no IN ({$placeholders})",
                    $eventNos,
                    $types
                )->fetchAllAssociative()
            );

            foreach ($rows as $row) {
                $eventsByStream[$streamTable][(int) $row['no']] = $rowAccess->convertToEvent($row, true);
            }
        }

        return $eventsByStream;
    }
}