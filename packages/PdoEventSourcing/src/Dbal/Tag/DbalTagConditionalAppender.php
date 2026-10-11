<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Dbal\Tag;

use function array_column;

use Doctrine\DBAL\Connection;
use Ecotone\Api\EventSourcing\AppendCondition;
use Ecotone\EventSourcing\Dbal\DbalEventStore;
use Ecotone\EventSourcing\Dbal\EventStreamSchema;
use Ecotone\EventSourcing\Tagging\AppendedTags;
use Ecotone\EventSourcing\Tagging\TagResolver;

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
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
final class DbalTagConditionalAppender
{
    public function __construct(
        private readonly TagResolver $tagResolver,
        private readonly DbalTagTables $tables,
        private readonly DbalTagVersionRegister $versions,
        private readonly DbalTagIndex $index,
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
        $rows = $eventStore->rowsToAppend($streamName, $events);
        $appended = $this->tagResolver->resolveAppend($events, $appendCondition);

        if (! $appended->involvesAnyTag()) {
            $eventStore->insertEventRows($connection, $schema, $streamName, $tableName, $rows);

            return;
        }

        TagTransactionRequirement::assertActiveForTaggedAppend($connection);

        $expected = $this->tables->raisingMissingTablesInstruction($eventStore, fn () => $this->bumpGuarded($connection, $appended));

        $eventStore->insertEventRows($connection, $schema, $streamName, $tableName, $rows);
        $this->index->insertRows($connection, $tableName, array_column($rows, 0), $appended->sequencedAfterBump($expected));
    }

    public function bumpTagsGuarded(DbalEventStore $eventStore, Connection $connection, AppendCondition $appendCondition): void
    {
        $appended = $this->tagResolver->resolveAppend([], $appendCondition);

        TagTransactionRequirement::assertActiveForTaggedAppend($connection);

        $this->tables->raisingMissingTablesInstruction($eventStore, fn () => $this->bumpGuarded($connection, $appended));
    }

    /**
     * @return array<string, array{name: string, value: string, expectedVersion: int, aggregateType?: string, decidedBy?: string[]}>
     */
    private function bumpGuarded(Connection $connection, AppendedTags $appended): array
    {
        $expected = $appended->expectedVersions($this->versions->capture($connection, $appended->needingCapture()));
        $this->versions->bumpGuarded($connection, $expected);

        return $expected;
    }
}
