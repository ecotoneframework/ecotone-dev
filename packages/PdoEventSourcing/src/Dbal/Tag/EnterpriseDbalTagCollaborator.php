<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Dbal\Tag;

use Doctrine\DBAL\Connection;
use Ecotone\Api\EventSourcing\AppendCondition;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\EventSourcing\LoadedEvents;
use Ecotone\EventSourcing\Database\TagTableManager;
use Ecotone\EventSourcing\Dbal\EventStreamSchema;
use Ecotone\EventSourcing\Tagging\EventTagRegistry;

/**
 * licence Enterprise
 */
final class EnterpriseDbalTagCollaborator implements DbalTagCollaborator
{
    private DbalTagConditionalAppender $appender;

    private DbalTaggedEventReader $reader;

    private DbalTagBackfiller $backfiller;

    public function __construct(EventTagRegistry $eventTagRegistry)
    {
        $versionRegister = new DbalTagVersionRegister();

        $this->appender = new DbalTagConditionalAppender($eventTagRegistry, $versionRegister);
        $this->reader = new DbalTaggedEventReader($versionRegister);
        $this->backfiller = new DbalTagBackfiller($eventTagRegistry, $versionRegister);
    }

    public function loadByCriteria(DbalEventRowAccess $rowAccess, Connection $connection, EventCriteria $criteria): LoadedEvents
    {
        return $this->reader->loadByCriteria($rowAccess, $connection, $criteria);
    }

    public function appendEventsWithTagCondition(
        DbalEventRowAccess $rowAccess,
        Connection $connection,
        EventStreamSchema $schema,
        string $tableName,
        string $streamName,
        array $events,
        ?AppendCondition $appendCondition,
    ): void {
        $this->appender->appendEventsWithTagCondition($rowAccess, $connection, $schema, $tableName, $streamName, $events, $appendCondition);
    }

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
    ): array {
        return $this->backfiller->backfillTagsForStream($rowAccess, $connection, $schema, $tableName, $streamName, $onlyEventName, $fromNo, $batchSize, $dryRun, $skipUndeserializable);
    }

    public function deleteTagIndexFor(Connection $connection, string $tableName): void
    {
        $tagSchema = TaggedEventSchemaFactory::for($connection);
        if (! $tagSchema->tableExists($connection, TagTableManager::TAGGED_EVENTS_TABLE)) {
            return;
        }

        $connection->executeStatement(
            'DELETE FROM ' . $tagSchema->quoteIdentifier(TagTableManager::TAGGED_EVENTS_TABLE) . ' WHERE stream_name = ?',
            [$tableName]
        );
    }
}