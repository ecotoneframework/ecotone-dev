<?php

declare(strict_types=1);

namespace Ecotone\EventSourcingV2\EventStore\SQL;

use Ecotone\EventSourcingV2\EventStore\Dbal\Connection;
use Ecotone\EventSourcingV2\EventStore\Event;
use Ecotone\EventSourcingV2\EventStore\EventStore;
use Ecotone\EventSourcingV2\EventStore\LogEventId;
use Ecotone\EventSourcingV2\EventStore\PersistedEvent;
use Ecotone\EventSourcingV2\EventStore\Projection\InlineProjectionManager;
use Ecotone\EventSourcingV2\EventStore\Projection\Projector;
use Ecotone\EventSourcingV2\EventStore\Projection\ProjectorWithSetup;
use Ecotone\EventSourcingV2\EventStore\StreamEventId;
use Ecotone\EventSourcingV2\EventStore\Subscription\EventLoader;
use Ecotone\EventSourcingV2\EventStore\Subscription\EventPage;
use Ecotone\EventSourcingV2\EventStore\Subscription\PersistentSubscriptions;
use Ecotone\EventSourcingV2\EventStore\Subscription\SubscriptionQuery;

class PostgresEventStore implements EventStore, EventLoader, PersistentSubscriptions, InlineProjectionManager
{
    protected bool $schemaIsKnownToExists = false;

    /**
     * @param array<string, Projector> $projectors
     */
    public function __construct(
        protected Connection $connection,
        protected array $projectors = [],
        protected bool $ignoreUnknownProjectors = true,
        protected string $eventTableName = 'es_event',
        protected string $streamTableName = 'es_stream',
        protected string $subscriptionTableName = 'es_subscription',
        protected string $projectionTableName = 'es_projection',
        protected bool $createSchema = true,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function append(StreamEventId $eventStreamId, array $events): array
    {
        $this->ensureSchemaExists();
        $transaction = $this->connection->beginTransaction();
        try {
            $streamVersionStatement = $this->connection->prepare("SELECT version FROM {$this->streamTableName} WHERE stream_id = ?");
            $streamVersionStatement->execute([(string) $eventStreamId->streamId]);
            $actualStreamVersion = $streamVersionStatement->fetchColumn() ?: null;

            if ($eventStreamId->version && $actualStreamVersion !== $eventStreamId->version) {
                throw new \RuntimeException('Concurrency error. Expected version ' . $eventStreamId->version . ' but got ' . $actualStreamVersion);
            }
            $version = $actualStreamVersion ?? 0;
            $statement = $this->connection->prepare(<<<SQL
                INSERT INTO {$this->eventTableName} (stream_id, version, event_type, payload, metadata)
                VALUES (?, ?, ?, ?, ?)
                RETURNING id, transaction_id
                SQL);
            $persistedEvents = [];
            foreach ($events as $event) {
                $statement->execute([
                    $eventStreamId->streamId,
                    $version++,
                    $event->type,
                    json_encode($event->payload, JSON_FORCE_OBJECT),
                    json_encode($event->metadata, JSON_FORCE_OBJECT),
                ]);
                $row = $statement->fetch();
                $persistedEvents[] = new PersistedEvent(
                    new StreamEventId($eventStreamId->streamId, $version),
                    new LogEventId((int) $row['transaction_id'], (int) $row['id']),
                    $event,
                );
            }
            if ($actualStreamVersion === null) {
                $this->connection->prepare("INSERT INTO {$this->streamTableName} (stream_id, version) VALUES (?, ?)")->execute([$eventStreamId->streamId, $version]);
            } else {
                $this->connection->prepare("UPDATE {$this->streamTableName} SET version = ? WHERE stream_id = ?")->execute([$version, $eventStreamId->streamId]);
            }

            $this->runProjectionsWith($persistedEvents);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return $persistedEvents;
    }

    /**
     * @inheritDoc
     */
    public function load(StreamEventId $eventStreamId): iterable
    {
        $statement = $this->connection->prepare("SELECT id, transaction_id, version, event_type, payload FROM {$this->eventTableName} WHERE stream_id = ? ORDER BY id");
        $statement->execute([$eventStreamId->streamId]);

        $events = [];
        while ($row = $statement->fetch()) {
            $events[] = new PersistedEvent(
                new StreamEventId($eventStreamId->streamId, (int) $row['version']),
                new LogEventId((int) $row['transaction_id'], (int) $row['id']),
                new Event($row['event_type'], $row['payload']),
            );
        }

        return $events;
    }

    /**
     * @return iterable<PersistedEvent>
     */
    public function query(SubscriptionQuery $query): iterable
    {
        $whereParts = [];
        $params = [];
        if ($query->streamIds) {
            $whereParts[] = 'e.stream_id IN (' . implode(', ', array_fill(0, count($query->streamIds), '?')) . ')';
            $params = array_merge($params, $query->streamIds);
        }
        if ($query->from) {
            $whereParts[] = '(e.transaction_id, e.id) > (?, ?)';
            $params[] = $query->from->transactionId;
            $params[] = $query->from->sequenceNumber;
        }
        if ($query->allowGaps === false) {
            $whereParts[] = "e.transaction_id < pg_snapshot_xmin(pg_current_snapshot())";
        }

        $where = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';
        $limit = $query->limit ? "LIMIT {$query->limit}" : '';

        $query = <<<SQL
SELECT e.id, e.transaction_id, e.stream_id, e.version, e.event_type, e.payload
FROM {$this->eventTableName} e 
{$where}
ORDER BY e.transaction_id, e.id
{$limit}
SQL;

        $statement = $this->connection->prepare($query);
        $statement->execute($params);

        while ($row = $statement->fetch()) {
            yield new PersistedEvent(
                new StreamEventId($row['stream_id'], (int) $row['version']),
                new LogEventId((int) $row['transaction_id'], (int) $row['id']),
                new Event($row['event_type'], $row['payload']),
            );
        }
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    /**
     * PersistentSubscriptions
     */
    public function createSubscription(string $subscriptionName, SubscriptionQuery $subscriptionQuery): void
    {
        $this->ensureSchemaExists();

        $position = $subscriptionQuery->from ?? LogEventId::start();
        $this->connection->prepare(<<<SQL
            INSERT INTO {$this->subscriptionTableName} (transaction_id, event_id, name, query)
            VALUES (?, ?, ?, ?)
            SQL)
            ->execute([
                $position->transactionId, $position->sequenceNumber, $subscriptionName, \json_encode($subscriptionQuery),
            ]);
    }

    public function deleteSubscription(string $subscriptionName): void
    {
        $this->ensureSchemaExists();

        $this->connection->prepare(<<<SQL
            DELETE FROM {$this->subscriptionTableName}
            WHERE name = ?
            SQL)
            ->execute([$subscriptionName]);
    }

    public function readFromSubscription(string $subscriptionName): EventPage
    {
        $statement = $this->connection->prepare(<<<SQL
            SELECT transaction_id, event_id, query
            FROM {$this->subscriptionTableName}
            WHERE name = ?
            -- FOR UPDATE
            SQL);
        $statement->execute([$subscriptionName]);
        $row = $statement->fetch();
        if (!$row) {
            throw new \RuntimeException(\sprintf('Subscription "%s" not found', $subscriptionName));
        }
        $startPosition = new LogEventId((int) $row['transaction_id'], (int) $row['event_id']);
        $baseQueryData = \json_decode($row['query'], true);
        $baseQuery = new SubscriptionQuery(
            streamIds: $baseQueryData['streamIds'] ?? null,
            from: $startPosition,
            allowGaps: (bool) $baseQueryData['allowGaps'] ?? false,
            limit: (int) $baseQueryData['limit'] ?? self::DEFAULT_BATCH_SIZE,
        );
        $events = [];
        $position = null;
        /** @var PersistedEvent $event */
        foreach ($this->query($baseQuery) as $event) {
            $events[] = $event;
            $position = $event->logEventId;
        }

        return new EventPage(
            $subscriptionName,
            $events,
            $startPosition,
            $position ?? $startPosition,
            $baseQuery->limit);
    }

    public function ack(EventPage $page): void
    {
        // todo: ensure the transaction is not already acked
        $statement = $this->connection->prepare(<<<SQL
            UPDATE {$this->subscriptionTableName}
            SET transaction_id = ?, event_id = ?
            WHERE name = ?
            SQL);
        $statement->execute([$page->endPosition->transactionId, $page->endPosition->sequenceNumber, $page->subscriptionName]);
        if ($statement->rowCount() === 0) {
            throw new \RuntimeException(\sprintf('Subscription "%s" not found', $page->subscriptionName));
        }
    }

    /**
     * InlineProjectionManager
     */
    public function runProjectionsWith(array $events): void
    {
        $statement = $this->connection->prepare(<<<SQL
SELECT name FROM {$this->projectionTableName}
WHERE state = 'inline' AND (
    after_transaction_id IS NULL 
        OR 
    after_transaction_id < pg_current_xact_id())
AND (
    before_transaction_id IS NULL 
        OR 
    before_transaction_id < pg_current_xact_id())
ORDER BY name
FOR SHARE
SQL);
        $statement->execute();

        while ($projection = $statement->fetch()) {
            $projector = $this->projectors[$projection['name']] ?? null;
            if (!$projector) {
                if ($this->ignoreUnknownProjectors) {
                    continue;
                }
                throw new \RuntimeException(\sprintf('Unknown projector "%s"', $projection['projector']));
            }
            foreach ($events as $event) {
                $projector->project($event);
            }
        }
    }

    public function addProjection(string $projectorName, string $state = "catchup"): void
    {
        $this->ensureSchemaExists();

        $projector = $this->getProjector($projectorName);

        $this->connection->prepare(<<<SQL
            INSERT INTO {$this->projectionTableName} (name, state)
            VALUES (?, ?)
            SQL)
            ->execute([$projectorName, $state]);

        if ($projector instanceof ProjectorWithSetup) {
            $projector->setUp();
        }
    }

    public function removeProjection(string $projectorName): void
    {
        $this->ensureSchemaExists();

        $this->connection->prepare(<<<SQL
            DELETE FROM {$this->projectionTableName}
            WHERE name = ?
            SQL)
            ->execute([$projectorName]);

        $this->deleteSubscription($projectorName);

        try {
            $projector = $this->getProjector($projectorName);
            if ($projector instanceof ProjectorWithSetup) {
                $projector->tearDown();
            }
        } catch (\RuntimeException) {
            // ignore
        }
    }

    public function catchupProjection(string $projectorName, int $missingEventsMaxLoops = 100): void
    {
        $this->ensureSchemaExists();

        $transaction = $this->connection->beginTransaction();
        try {
            $statement = $this->connection->prepare(<<<SQL
                SELECT name, state
                FROM {$this->projectionTableName}
                WHERE name = ?
                FOR UPDATE
                SQL);
            $statement->execute([$projectorName]);
            $projection = $statement->fetch();
            if (!$projection) {
                throw new \RuntimeException('Projection not found');
            }
            if ($projection['state'] === 'catchup') {
                $statement = $this->connection->prepare(<<<SQL
                    UPDATE {$this->projectionTableName}
                    SET state = 'catching_up'
                    WHERE name = ?
                    SQL);
                $statement->execute([$projectorName]);
                $this->createSubscription($projectorName, new SubscriptionQuery(limit: 1000));
            } elseif ($projection['state'] !== 'catching_up') {
                throw new \RuntimeException('Cannot catchup projection in state ' . $projection['state']);
            }
            $projector = $this->getProjector($projectorName);

           $transaction->commit();
        } catch (\Throwable $e) {
           $transaction->rollBack();
            throw $e;
        }

        do {
            $page = $this->readFromSubscription($projectorName);
            if ($page->events === []) {
                break;
            }
            $transaction = $this->connection->beginTransaction();
            try {
                foreach ($page->events as $event) {
                    $projector->project($event);
                }
                $this->ack($page);
                $transaction->commit();
            } catch (\Throwable $e) {
                $transaction->rollBack();
                throw $e;
            }
        } while (true);

        $transaction = $this->connection->beginTransaction();
        try {
            $lastTransactionIdStatement = $this->connection->prepare(<<<SQL
                UPDATE {$this->projectionTableName}
                SET state = 'inline', after_transaction_id = pg_snapshot_xmax(pg_current_snapshot()), before_transaction_id = NULL
                WHERE name = ?
                RETURNING after_transaction_id
                SQL);
            $lastTransactionIdStatement->execute([$projectorName]);
            $lastTransactionId = $lastTransactionIdStatement->fetchColumn();

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }


        // Execute missing events
        $missingEventsLoop = 0;
        $currentXminStatement = $this->connection->prepare("SELECT pg_snapshot_xmin(pg_current_snapshot())");
        while ($missingEventsLoop < $missingEventsMaxLoops) {
            $transaction = $this->connection->beginTransaction();
            try {
                $currentXminStatement->execute();
                $currentXmin = $currentXminStatement->fetchColumn();
                $page = $this->readFromSubscription($projectorName);
                foreach ($page->events as $event) {
                    if ($event->logEventId->transactionId > $lastTransactionId) {
                        break;
                    }
                    $projector->project($event);
                }
                $this->ack($page);

                $transaction->commit();
            } catch (\Throwable $e) {
                $transaction->rollBack();
                throw $e;
            }

            if ($currentXmin > $lastTransactionId) {
                $this->persistentSubscriptions()->deleteSubscription($projectorName);
                break;
            }

            $missingEventsLoop++;
            \usleep(1000);
        }
    }

    public function switchProjectionToSubscription(string $projectionName): void
    {
        $this->ensureSchemaExists();

        $transaction = $this->connection->beginTransaction();
        try {
            $statement = $this->connection->prepare(<<<SQL
                SELECT name, state
                FROM {$this->projectionTableName}
                WHERE name = ?
                FOR UPDATE
                SQL);
            $statement->execute([$projectionName]);
            $projection = $statement->fetch();
            if (!$projection) {
                throw new \RuntimeException('Projection not found');
            }
            if ($projection['state'] !== 'inline') {
                throw new \RuntimeException('Cannot switch projection in state ' . $projection['state']);
            }
            $statement = $this->connection->prepare(<<<SQL
                UPDATE {$this->projectionTableName}
                SET before_transaction_id = pg_snapshot_xmax(pg_current_snapshot())
                WHERE name = ?
                RETURNING before_transaction_id
                SQL);
            $statement->execute([$projectionName]);
            $beforeTransactionId = $statement->fetchColumn();
            $this->createSubscription($projectionName, new SubscriptionQuery(from: new LogEventId($beforeTransactionId, 0)));

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    protected function getProjector(string $projectorName): Projector
    {
        $projector = $this->projectors[$projectorName] ?? null;
        if (!$projector) {
            throw new \RuntimeException('Unknown projector ' . $projectorName);
        }
        return $projector;
    }

    protected function persistentSubscriptions(): PersistentSubscriptions
    {
        return $this;
    }

    public function schemaUp(): void
    {
        $this->connection->execute(<<<SQL
CREATE TABLE IF NOT EXISTS {$this->eventTableName}
(
    id             BIGSERIAL PRIMARY KEY,
    transaction_id XID8    NOT NULL DEFAULT pg_current_xact_id(),
    stream_id      UUID    NOT NULL,
    version        INTEGER NOT NULL,
    event_type     TEXT    NOT NULL,
    payload        JSON    NOT NULL,
    metadata       JSON    NOT NULL DEFAULT '{}',
    UNIQUE (stream_id, version)
);

CREATE INDEX IF NOT EXISTS idx_{$this->eventTableName}_transaction_id_id ON {$this->eventTableName} (transaction_id, id);
CREATE INDEX IF NOT EXISTS idx_{$this->eventTableName}_stream_id ON {$this->eventTableName} (stream_id);
CREATE INDEX IF NOT EXISTS idx_{$this->eventTableName}_version ON {$this->eventTableName} (version);

CREATE TABLE IF NOT EXISTS {$this->streamTableName}
(
    stream_id UUID NOT NULL PRIMARY KEY,
    version   INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS {$this->projectionTableName}
(
    name                 TEXT NOT NULL PRIMARY KEY,
    state                TEXT NOT NULL,
    after_transaction_id XID8,
    before_transaction_id XID8,
    metadata             JSONB DEFAULT NULL
);
CREATE INDEX IF NOT EXISTS idx_{$this->projectionTableName}_state ON {$this->projectionTableName} (state);
CREATE INDEX IF NOT EXISTS idx_{$this->projectionTableName}_after_transaction_id ON {$this->projectionTableName} (after_transaction_id);
CREATE INDEX IF NOT EXISTS idx_{$this->projectionTableName}_before_transaction_id ON {$this->projectionTableName} (before_transaction_id);

CREATE TABLE IF NOT EXISTS {$this->subscriptionTableName}
(
    name      TEXT   NOT NULL PRIMARY KEY,
    transaction_id XID8   NOT NULL,
    event_id       BIGINT NOT NULL,
    query          JSON   NOT NULL
);
SQL);
    }

    public function schemaDown(): void
    {
        $this->connection->execute(<<<SQL
DROP TABLE IF EXISTS {$this->eventTableName};
    
DROP TABLE IF EXISTS {$this->streamTableName};

DROP TABLE IF EXISTS {$this->projectionTableName};

DROP TABLE IF EXISTS {$this->subscriptionTableName};
SQL);
    }

    protected function ensureSchemaExists(): void
    {
        if (!$this->schemaIsKnownToExists && $this->createSchema) {
            $statement = $this->connection->prepare("SELECT to_regclass(?)");
            $statement->execute([$this->eventTableName]);
            if ($statement->fetchColumn() === null) {
                $this->schemaUp();
            }
            $this->schemaIsKnownToExists = true;
        }
    }
}