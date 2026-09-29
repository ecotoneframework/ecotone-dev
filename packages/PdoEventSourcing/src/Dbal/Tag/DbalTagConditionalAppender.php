<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Dbal\Tag;

use function array_column;

use Doctrine\DBAL\Connection;
use Ecotone\Api\EventSourcing\AppendCondition;
use Ecotone\EventSourcing\Dbal\DbalEventStore;
use Ecotone\EventSourcing\Dbal\EventStreamSchema;
use Ecotone\EventSourcing\StreamTableRegistry;
use Ecotone\EventSourcing\Tagging\TagResolver;

/**
 * licence Enterprise
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
            $eventStore->insertEventRows($connection, $schema, $tableName, $rows);

            return;
        }

        TagTransactionRequirement::assertActiveForTaggedAppend($connection);
        $this->tables->ensureExist($eventStore, $connection, $streamName);

        $captured = $this->versions->capture($connection, $appended->needingCapture());
        $expected = $appended->expectedVersions($captured);
        $this->versions->bumpGuarded($connection, $expected);

        $eventStore->insertEventRows($connection, $schema, $tableName, $rows);
        $this->index->insertRows($connection, $tableName, array_column($rows, 0), $appended->sequencedAfterBump($expected));
    }

    public function bumpTagsGuarded(DbalEventStore $eventStore, Connection $connection, AppendCondition $appendCondition): void
    {
        $appended = $this->tagResolver->resolveAppend([], $appendCondition);

        TagTransactionRequirement::assertActiveForTaggedAppend($connection);
        $this->tables->ensureExist($eventStore, $connection, StreamTableRegistry::DEFAULT_STREAM);

        $this->versions->bumpGuarded($connection, $appended->expectedVersions($this->versions->capture($connection, $appended->needingCapture())));
    }
}
