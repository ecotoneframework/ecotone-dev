<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Dbal\Tag;

use Doctrine\DBAL\Connection;
use Ecotone\Api\EventSourcing\AppendCondition;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\EventSourcing\LoadedEvents;
use Ecotone\EventSourcing\Dbal\DbalEventStore;
use Ecotone\EventSourcing\Dbal\EventStreamSchema;
use Ecotone\EventSourcing\Tagging\TagResolver;

/**
 * licence Enterprise
 */
final class EnterpriseDbalTagCollaborator implements DbalTagCollaborator
{
    private DbalTagConditionalAppender $appender;

    private DbalTaggedEventReader $reader;

    private DbalTagBackfiller $backfiller;

    private DbalTagIndex $index;

    public function __construct(TagResolver $tagResolver)
    {
        $tables = new DbalTagTables();
        $versions = new DbalTagVersionRegister();
        $this->index = new DbalTagIndex();

        $this->appender = new DbalTagConditionalAppender($tagResolver, $tables, $versions, $this->index);
        $this->reader = new DbalTaggedEventReader($tagResolver, $tables, $versions, $this->index);
        $this->backfiller = new DbalTagBackfiller($tagResolver, $tables, $versions, $this->index);
    }

    public function loadByCriteria(DbalEventStore $eventStore, Connection $connection, EventCriteria $criteria): LoadedEvents
    {
        return $this->reader->loadByCriteria($eventStore, $connection, $criteria);
    }

    public function appendEventsWithTagCondition(
        DbalEventStore $eventStore,
        Connection $connection,
        EventStreamSchema $schema,
        string $tableName,
        string $streamName,
        array $events,
        ?AppendCondition $appendCondition,
    ): void {
        $this->appender->appendEventsWithTagCondition($eventStore, $connection, $schema, $tableName, $streamName, $events, $appendCondition);
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
        $this->backfiller->backfillTagsForStream($eventStore, $connection, $schema, $tableName, $streamName, $onlyEventName, $fromNo, $batchSize, $dryRun, $skipUndeserializable, $report);
    }

    public function deleteTagIndexFor(Connection $connection, string $tableName): void
    {
        $this->index->deleteForStream($connection, $tableName);
    }

    public function bumpTagsGuarded(DbalEventStore $eventStore, Connection $connection, AppendCondition $appendCondition): void
    {
        $this->appender->bumpTagsGuarded($eventStore, $connection, $appendCondition);
    }
}
