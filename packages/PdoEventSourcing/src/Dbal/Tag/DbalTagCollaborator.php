<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Dbal\Tag;

use Doctrine\DBAL\Connection;
use Ecotone\Api\EventSourcing\AppendCondition;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\EventSourcing\LoadedEvents;
use Ecotone\EventSourcing\Dbal\EventStreamSchema;

/**
 * The three DCB entry points DbalEventStore delegates to, chosen once at
 * bootstrap by licence via LicenceDecider (the same way AppendStrategy is).
 *
 * licence Apache-2.0
 */
interface DbalTagCollaborator
{
    public function loadByCriteria(DbalEventRowAccess $rowAccess, Connection $connection, EventCriteria $criteria): LoadedEvents;

    /**
     * @param object[]|array[] $events
     */
    public function appendEventsWithTagCondition(
        DbalEventRowAccess $rowAccess,
        Connection $connection,
        EventStreamSchema $schema,
        string $tableName,
        string $streamName,
        array $events,
        ?AppendCondition $appendCondition,
    ): void;

    /**
     * @return array{lastNo: int, eventsScanned: int, eventsTagged: int, tagsBumped: int, undeserializable: array<int>}
     */
    public function backfillTagsForStream(
        DbalEventRowAccess $rowAccess,
        Connection $connection,
        EventStreamSchema $schema,
        string $tableName,
        string $streamName,
        ?string $onlyEventName,
        ?int $fromNo,
        int $batchSize,
        bool $dryRun,
        bool $skipUndeserializable,
    ): array;

    public function deleteTagIndexFor(Connection $connection, string $tableName): void;
}