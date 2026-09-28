<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Dbal\Tag;

use Doctrine\DBAL\Connection;
use Ecotone\Api\EventSourcing\AppendCondition;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\EventSourcing\LoadedEvents;
use Ecotone\EventSourcing\Dbal\DbalEventStore;
use Ecotone\EventSourcing\Dbal\EventStreamSchema;

/**
 * licence Apache-2.0
 */
interface DbalTagCollaborator
{
    public function loadByCriteria(DbalEventStore $eventStore, Connection $connection, EventCriteria $criteria): LoadedEvents;

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
    ): void;

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
    ): void;

    public function deleteTagIndexFor(Connection $connection, string $tableName): void;
}
