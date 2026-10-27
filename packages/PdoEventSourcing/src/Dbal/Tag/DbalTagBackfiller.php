<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Dbal\Tag;

use function array_diff_key;
use function count;

use Doctrine\DBAL\Connection;
use Ecotone\EventSourcing\Dbal\DbalEventStore;
use Ecotone\EventSourcing\Dbal\EventStreamSchema;
use Ecotone\EventSourcing\Tagging\TagResolver;
use Ecotone\Messaging\Config\ConfigurationException;

use function ksort;
use function sprintf;

use Throwable;

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
final class DbalTagBackfiller
{
    public function __construct(
        private readonly TagResolver $tagResolver,
        private readonly DbalTagTables $tables,
        private readonly DbalTagVersionRegister $versions,
        private readonly DbalTagIndex $index,
    ) {
    }

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
        TagBackfillReport $report,
    ): void {
        if (! $schema->tableExists($connection, $tableName)) {
            throw $eventStore->missingStreamTableException($tableName);
        }

        if (! $dryRun) {
            TagTransactionRequirement::assertActiveForTagBackfill($connection);
        }

        $position = $fromNo ?? 1;
        do {
            $rows = $eventStore->loadRowBatch($connection, $schema, $tableName, $position, $onlyEventName, $batchSize);
            $batch = $this->deserialize($eventStore, $rows, $streamName, $skipUndeserializable);

            $report->recordScanned($batch);
            $this->tables->raisingMissingTablesInstruction($eventStore, fn () => $this->indexBatch($connection, $tableName, $batch, $dryRun, $report));

            $position = $report->lastNo() + 1;
        } while ($rows !== [] && count($rows) === $batchSize);
    }

    private function indexBatch(Connection $connection, string $tableName, TagBackfillBatch $batch, bool $dryRun, TagBackfillReport $report): void
    {
        if (! $batch->hasTaggedEvents()) {
            return;
        }

        $report->recordTagged($batch->taggedEventCount());

        if ($dryRun) {
            $report->recordBumped(count($batch->tags()->counted()));

            return;
        }

        $pendingTags = $this->pendingTags($connection, $tableName, $batch);
        $this->versions->increment($connection, $pendingTags);
        $report->recordBumped(count($pendingTags));

        $versionsAfterBump = $this->versions->currentVersions($connection, $pendingTags);
        $this->index->insertRowsIdempotently($connection, $tableName, $batch->eventIds(), $batch->tags()->sequencedBy($versionsAfterBump));
    }

    /**
     * @return array<string, array{name: string, value: string}>
     */
    private function pendingTags(Connection $connection, string $tableName, TagBackfillBatch $batch): array
    {
        $alreadyIndexed = $this->index->indexedTagKeysOfEvent($connection, $tableName, $batch->firstTaggedEventId());
        $pending = array_diff_key($batch->tags()->counted(), $alreadyIndexed);
        ksort($pending);

        return $pending;
    }

    /**
     * @param array<array<string, mixed>> $rows
     */
    private function deserialize(DbalEventStore $eventStore, array $rows, string $streamName, bool $skipUndeserializable): TagBackfillBatch
    {
        $events = [];
        $eventIds = [];
        $undeserializable = [];
        $lastNo = null;

        foreach ($rows as $row) {
            $lastNo = (int) $row['no'];

            try {
                $events[] = $eventStore->convertToEvent($row, true);
                $eventIds[] = $row['event_id'];
            } catch (Throwable $exception) {
                if (! $skipUndeserializable) {
                    throw ConfigurationException::create(sprintf(
                        "Event no %d in stream '%s' could not be deserialized: %s. Re-run with --skipUndeserializable to skip it.",
                        $row['no'],
                        $streamName,
                        $exception->getMessage(),
                    ));
                }

                $undeserializable[] = $lastNo;
            }
        }

        return new TagBackfillBatch(count($rows), $lastNo, $undeserializable, $eventIds, $this->tagResolver->resolveEvents($events));
    }
}
