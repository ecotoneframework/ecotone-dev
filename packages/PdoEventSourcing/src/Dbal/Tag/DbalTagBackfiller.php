<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Dbal\Tag;

use function count;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Ecotone\EventSourcing\Database\TagTableManager;
use Ecotone\EventSourcing\Dbal\DbalEventStore;
use Ecotone\EventSourcing\Dbal\EventStreamSchema;
use Ecotone\EventSourcing\Tagging\EventTagRegistry;
use Ecotone\Messaging\Config\ConfigurationException;

use function implode;
use function is_object;
use function ksort;
use function sprintf;

use Throwable;

/**
 * licence Enterprise
 */
final class DbalTagBackfiller
{
    public function __construct(
        private readonly EventTagRegistry $eventTagRegistry,
        private readonly DbalTagVersionRegister $versionRegister,
    ) {
    }

    /**
     * @return array{lastNo: int, eventsScanned: int, eventsTagged: int, tagsBumped: int, undeserializable: array<int>}
     */
    public function backfillTagsForStream(
        DbalEventStore $eventStore,
        Connection $connection,
        EventStreamSchema $schema,
        string $tableName,
        string $streamName,
        ?string $onlyEventName,
        ?int $fromNo,
        int $batchSize,
        bool $dryRun,
        bool $skipUndeserializable,
    ): array {
        $report = ['lastNo' => ($fromNo ?? 1) - 1, 'eventsScanned' => 0, 'eventsTagged' => 0, 'tagsBumped' => 0, 'undeserializable' => []];

        if (! $schema->tableExists($connection, $tableName)) {
            return $report;
        }

        $tagSchema = TaggedEventSchemaFactory::for($connection);
        if (! $dryRun) {
            TagTransactionRequirement::assertActiveForTagBackfill($connection);
            $this->versionRegister->ensureTagTablesExist($eventStore, $connection, $streamName);
        }

        $position = $fromNo ?? 1;

        while (true) {
            $where = ['no >= ?'];
            $parameters = [$position];
            $types = [ParameterType::INTEGER];
            if ($onlyEventName !== null) {
                $where[] = 'event_name = ?';
                $parameters[] = $onlyEventName;
                $types[] = ParameterType::STRING;
            }

            $rows = $connection->executeQuery(
                'SELECT no, event_id, event_name, payload, metadata FROM ' . $schema->quoteIdentifier($tableName)
                . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY no ASC LIMIT ' . $batchSize,
                $parameters,
                $types
            )->fetchAllAssociative();

            if ($rows === []) {
                break;
            }

            $batchTagsInvolved = [];
            $perEventTags = [];
            $eventIds = [];

            foreach ($rows as $row) {
                $report['eventsScanned']++;
                $report['lastNo'] = (int) $row['no'];

                try {
                    $event = $eventStore->convertToEvent($row, true);
                } catch (Throwable $exception) {
                    if (! $skipUndeserializable) {
                        throw ConfigurationException::create(sprintf(
                            "Event no %d in stream '%s' could not be deserialized: %s. Re-run with --skip-undeserializable to skip it.",
                            $row['no'],
                            $streamName,
                            $exception->getMessage(),
                        ));
                    }

                    $report['undeserializable'][] = (int) $row['no'];

                    continue;
                }

                $payload = $event->getPayload();
                $tags = is_object($payload) ? $this->eventTagRegistry->tagsFor($payload) : [];

                if ($tags === []) {
                    continue;
                }

                $report['eventsTagged']++;
                $eventIds[] = $row['event_id'];
                $perEventTags[] = $tags;

                foreach ($tags as $tag) {
                    if ($this->eventTagRegistry->isFilterOnly($tag['name'])) {
                        continue;
                    }

                    $batchTagsInvolved[$this->versionRegister->tagKey($tag['name'], $tag['value'])] = $tag;
                }
            }

            if ($eventIds !== [] && ! $dryRun) {
                ksort($batchTagsInvolved);
                $alreadyBackfilled = $this->tagsAlreadyIndexedFor($connection, $tagSchema, $tableName, $eventIds[0]);

                $newVersions = [];
                foreach ($batchTagsInvolved as $key => $tag) {
                    if (isset($alreadyBackfilled[$key])) {
                        continue;
                    }

                    $newVersions[$key] = $this->versionRegister->bumpUnconditionalTagVersion($connection, $tagSchema, $tag['name'], $tag['value']);
                    $report['tagsBumped']++;
                }

                if ($newVersions !== []) {
                    $this->insertTagIndexRowsIdempotent($connection, $tagSchema, $tableName, $eventIds, $perEventTags, $newVersions);
                }
            } elseif ($eventIds !== []) {
                $report['tagsBumped'] += count($batchTagsInvolved);
            }

            if (count($rows) < $batchSize) {
                break;
            }

            $position = $report['lastNo'] + 1;
        }

        return $report;
    }

    /**
     * @return array<string, true>
     */
    private function tagsAlreadyIndexedFor(Connection $connection, TaggedEventSchema $tagSchema, string $tableName, string $firstEventIdInBatch): array
    {
        $indexTable = $tagSchema->quoteIdentifier(TagTableManager::TAGGED_EVENTS_TABLE);

        $rows = $connection->executeQuery(
            "SELECT tag_name, tag_value FROM {$indexTable} WHERE stream_name = ? AND event_no = (SELECT no FROM " . $tagSchema->quoteIdentifier($tableName) . ' WHERE event_id = ?)',
            [$tableName, $firstEventIdInBatch]
        )->fetchAllAssociative();

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[$this->versionRegister->tagKey($row['tag_name'], $row['tag_value'])] = true;
        }

        return $indexed;
    }

    /**
     * @param string[] $eventIds
     * @param array<array<array{name: string, value: string}>> $perEventTags
     * @param array<string, int> $newVersions
     */
    private function insertTagIndexRowsIdempotent(Connection $connection, TaggedEventSchema $tagSchema, string $tableName, array $eventIds, array $perEventTags, array $newVersions): void
    {
        foreach ($eventIds as $i => $eventId) {
            foreach ($perEventTags[$i] as $tag) {
                $key = $this->versionRegister->tagKey($tag['name'], $tag['value']);
                if (! isset($newVersions[$key])) {
                    continue;
                }

                $tagSequence = $this->eventTagRegistry->isFilterOnly($tag['name']) ? 0 : $newVersions[$key];

                $connection->executeStatement(
                    $tagSchema->idempotentInsertIndexRowSql(TagTableManager::TAGGED_EVENTS_TABLE, $tableName),
                    [$tag['name'], $tag['value'], $tableName, $tagSequence, $eventId]
                );
            }
        }
    }
}
