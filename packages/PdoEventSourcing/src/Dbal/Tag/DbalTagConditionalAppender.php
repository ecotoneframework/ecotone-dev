<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Dbal\Tag;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Ecotone\Api\EventSourcing\AppendCondition;
use Ecotone\EventSourcing\Database\TagTableManager;
use Ecotone\EventSourcing\Dbal\DbalEventStore;
use Ecotone\EventSourcing\Dbal\EventStreamSchema;
use Ecotone\EventSourcing\Tagging\EventTagRegistry;
use Ecotone\Modelling\Event;

use function implode;
use function is_object;
use function ksort;

/**
 * licence Enterprise
 */
final class DbalTagConditionalAppender
{
    public function __construct(
        private readonly EventTagRegistry $eventTagRegistry,
        private readonly DbalTagVersionRegister $versionRegister,
    ) {
    }

    /**
     * @param object[]|array[] $events
     */
    public function appendEventsWithTagCondition(
        DbalEventStore $eventStore,
        Connection $connection,
        EventStreamSchema $schema,
        string $tableName,
        string $streamName,
        array $events,
        ?AppendCondition $appendCondition,
    ): void {
        $this->versionRegister->resetOwnBumpTrackingIfNoTransaction($connection);

        $rows = [];
        $eventIds = [];
        $perEventTags = [];
        $tagsInvolved = [];

        foreach ($events as $eventToConvert) {
            $row = $eventStore->convertToRow($eventToConvert);
            $eventStore->assertProjectionInvariant($streamName, $row[1], $eventToConvert);
            $rows[] = $row;
            $eventIds[] = $row[0];

            $tags = $this->resolveTags($eventToConvert);
            $perEventTags[] = $tags;
            foreach ($tags as $tag) {
                if ($this->eventTagRegistry->isFilterOnly($tag['name'])) {
                    continue;
                }

                $tagsInvolved[$this->versionRegister->tagKey($tag['name'], $tag['value'])] = $tag;
            }
        }

        $conditionTags = [];
        if ($appendCondition !== null) {
            foreach ($appendCondition->expectedTagVersions() as $expected) {
                $key = $this->versionRegister->tagKey($expected['name'], $expected['value']);
                $conditionTags[$key] = $expected;
                $tagsInvolved[$key] = ['name' => $expected['name'], 'value' => $expected['value']];
            }
        }

        if ($tagsInvolved === [] && ! self::anyEventHasATag($perEventTags)) {
            $eventStore->insertEventRows($connection, $schema, $tableName, $rows);

            return;
        }

        $this->versionRegister->ensureTagTablesExist($eventStore, $connection, $streamName);
        $tagSchema = TaggedEventSchemaFactory::for($connection);

        ksort($tagsInvolved);

        $write = function () use ($eventStore, $connection, $tagSchema, $schema, $tableName, $rows, $eventIds, $perEventTags, $tagsInvolved, $conditionTags): void {
            $newVersions = [];
            foreach ($tagsInvolved as $key => $tag) {
                $newVersions[$key] = isset($conditionTags[$key])
                    ? $this->versionRegister->bumpGuardedTagVersion($connection, $tagSchema, $tag['name'], $tag['value'], $conditionTags[$key]['expectedVersion'])
                    : $this->versionRegister->bumpUnconditionalTagVersion($connection, $tagSchema, $tag['name'], $tag['value']);
            }

            $eventStore->insertEventRows($connection, $schema, $tableName, $rows);
            $this->insertTagIndexRows($connection, $tagSchema, $tableName, $eventIds, $perEventTags, $newVersions);
        };

        if ($connection->isTransactionActive()) {
            $write();
        } else {
            $connection->transactional($write);
        }
    }

    /**
     * @param array<array<array{name: string, value: string}>> $perEventTags
     */
    private static function anyEventHasATag(array $perEventTags): bool
    {
        foreach ($perEventTags as $tags) {
            if ($tags !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<array{name: string, value: string}>
     */
    private function resolveTags(object|array $eventToConvert): array
    {
        $payload = $eventToConvert instanceof Event ? $eventToConvert->getPayload() : $eventToConvert;

        return is_object($payload) ? $this->eventTagRegistry->tagsFor($payload) : [];
    }

    /**
     * @param string[] $eventIds
     * @param array<array<array{name: string, value: string}>> $perEventTags
     * @param array<string, int> $newVersions
     */
    private function insertTagIndexRows(Connection $connection, TaggedEventSchema $tagSchema, string $tableName, array $eventIds, array $perEventTags, array $newVersions): void
    {
        $selects = [];
        $parameters = [];
        $types = [];

        foreach ($eventIds as $i => $eventId) {
            foreach ($perEventTags[$i] as $tag) {
                $tagSequence = $this->eventTagRegistry->isFilterOnly($tag['name']) ? 0 : $newVersions[$this->versionRegister->tagKey($tag['name'], $tag['value'])];

                $selects[] = 'SELECT ? AS tag_name, ? AS tag_value, ? AS stream_name, s.no AS event_no, ' . $tagSchema->bigIntPlaceholder() . ' AS tag_sequence FROM '
                    . $tagSchema->quoteIdentifier($tableName) . ' s WHERE s.event_id = ?';
                $parameters[] = $tag['name'];
                $types[] = ParameterType::STRING;
                $parameters[] = $tag['value'];
                $types[] = ParameterType::STRING;
                $parameters[] = $tableName;
                $types[] = ParameterType::STRING;
                $parameters[] = $tagSequence;
                $types[] = ParameterType::INTEGER;
                $parameters[] = $eventId;
                $types[] = ParameterType::STRING;
            }
        }

        if ($selects === []) {
            return;
        }

        $indexTable = $tagSchema->quoteIdentifier(TagTableManager::TAGGED_EVENTS_TABLE);
        $sql = "INSERT INTO {$indexTable} (tag_name, tag_value, stream_name, event_no, tag_sequence) " . implode(' UNION ALL ', $selects);

        $connection->executeStatement($sql, $parameters, $types);
    }
}