<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Dbal\Tag;

use Doctrine\DBAL\Connection;
use Ecotone\Api\EventSourcing\AppendCondition;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\EventSourcing\LoadedEvents;
use Ecotone\EventSourcing\Dbal\DbalEventStore;
use Ecotone\EventSourcing\Dbal\EventStreamSchema;
use Ecotone\Messaging\Support\LicensingException;

/**
 * licence Apache-2.0
 */
final class OpenCoreDbalTagCollaborator implements DbalTagCollaborator
{
    public function loadByCriteria(DbalEventStore $eventStore, Connection $connection, EventCriteria $criteria): LoadedEvents
    {
        throw LicensingException::create('Loading events by tag criteria (Dynamic Consistency Boundary) requires Ecotone Enterprise');
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
        throw LicensingException::create('Tag-based conditional append (Dynamic Consistency Boundary) requires Ecotone Enterprise');
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
    ): array {
        throw LicensingException::create('Backfilling tag indexes (Dynamic Consistency Boundary) requires Ecotone Enterprise');
    }

    public function deleteTagIndexFor(Connection $connection, string $tableName): void
    {
    }
}