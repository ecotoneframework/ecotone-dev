<?php

declare(strict_types=1);

namespace Ecotone\EventSourcingV2\EventStore\SQL;

use Ecotone\EventSourcingV2\EventStore\Dbal\Connection;
use Ecotone\EventSourcingV2\EventStore\Dbal\DriverException;
use Ecotone\EventSourcingV2\EventStore\Dbal\NoOpTransaction;
use Ecotone\EventSourcingV2\EventStore\Dbal\Statement;
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

class MysqlEventStore implements EventStore, EventLoader, PersistentSubscriptions, InlineProjectionManager
{
    protected bool $schemaIsKnownToExists = false;

    private const MYSQL_ER_LOCK_NOWAIT = 3572;
    private const MYSQL_ER_NO_SUCH_TABLE = 1146;
    /**
     * @param array<string, Projector> $projectors
     */
    public function __construct(
        private Connection $connection,
        protected array $projectors = [],
        protected bool $ignoreUnknownProjectors = true,
        protected string $eventTableName = 'es_event',
        protected string $streamTableName = 'es_stream',
        protected string $subscriptionTableName = 'es_subscription',
        protected string $projectionTableName = 'es_projection',
        protected bool $createSchema = true,
    )
    {
    }

    public function append(StreamEventId $eventStreamId, array $events): array
    {
        $this->ensureSchemaExists();

        $transaction = $this->connection->beginTransaction();
        try {
            $lastInsertIdStatement = $this->connection->prepare("SELECT LAST_INSERT_ID()");
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
                $lastInsertIdStatement->execute();
                $lastInsertId = $lastInsertIdStatement->fetchColumn();
                $persistedEvents[] = new PersistedEvent(
                    new StreamEventId($eventStreamId->streamId, $version),
                    new LogEventId(0, (int) $lastInsertId),
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

    public function load(StreamEventId $eventStreamId): iterable
    {
        $statement = $this->connection->prepare("SELECT id, version, event_type, payload FROM {$this->eventTableName} WHERE stream_id = ? ORDER BY id");
        $statement->execute([$eventStreamId->streamId]);

        $events = [];
        while ($row = $statement->fetch()) {
            $events[] = new PersistedEvent(
                new StreamEventId($eventStreamId->streamId, (int) $row['version']),
                new LogEventId(0, (int) $row['id']),
                new Event($row['event_type'], $row['payload']),
            );
        }

        return $events;
    }

    public function query(SubscriptionQuery $query): iterable
    {
        $whereParts = [];
        $params = [];
        if ($query->streamIds) {
            $whereParts[] = 'e.stream_id IN (' . implode(', ', array_fill(0, count($query->streamIds), '?')) . ')';
            $params = array_merge($params, $query->streamIds);
        }
        if ($query->from) {
            $whereParts[] = 'e.id > ?';
            $params[] = $query->from->sequenceNumber;
        }
        if ($query->to) {
            $whereParts[] = 'e.id <= ?';
            $params[] = $query->to->sequenceNumber;
        }

        $transaction = $this->connection->beginTransaction();
        try {
            $lock = '';
            if (! $query->allowGaps) {
                $maxIdStatement = $this->connection->prepare("SELECT MAX(id) FROM {$this->eventTableName}");
                $maxIdStatement->execute();
                $maxId = $maxIdStatement->fetchColumn();
                if ($maxId === null) {
                    return;
                }
                $whereParts[] = 'e.id <= ?';
                $params[] = $maxId;
                $lock = 'FOR SHARE NOWAIT';
            }
            $where = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';
            $limit = $query->limit ? "LIMIT {$query->limit}" : '';

            $sqlQuery = <<<SQL
                SELECT e.id, e.stream_id, e.version, e.event_type, e.payload
                FROM {$this->eventTableName} e 
                {$where}
                ORDER BY e.id
                SQL;

            $statement = $this->connection->prepare("$sqlQuery $limit $lock");
            $statement->execute($params);

            while ($row = $statement->fetch()) {
                yield new PersistedEvent(
                    new StreamEventId($row['stream_id'], (int) $row['version']),
                    new LogEventId(0, (int) $row['id']),
                    new Event($row['event_type'], $row['payload']),
                );
            }
        } catch (DriverException $e) {
            if ($e->getCode() === self::MYSQL_ER_LOCK_NOWAIT && isset($sqlQuery)) {
                // query row one by one in case of NOWAIT exception
                $statement = $this->connection->prepare("$sqlQuery LIMIT 1 OFFSET ? $lock");
                $offset = 0;
                $offsetParamPosition = count($params);
                while (true) {
                    try {
                        $statement->execute([
                            ...$params,
                            $offset,
                        ], [
                            $offsetParamPosition => Statement::PARAM_INT,
                        ]);
                        $row = $statement->fetch();
                        yield new PersistedEvent(
                            new StreamEventId($row['stream_id'], (int) $row['version']),
                            new LogEventId(0, (int) $row['id']),
                            new Event($row['event_type'], $row['payload']),
                        );
                    } catch (DriverException $e) {
                        if ($e->getCode() === self::MYSQL_ER_LOCK_NOWAIT) {
                            return;
                        } else {
                            throw $e;
                        }
                    }

                    $offset++;
                }
            } else {
                throw $e;
            }
        } finally {
            $transaction->commit();
        }
    }

    public function runProjectionsWith(array $events): void
    {
        $statement = $this->connection->prepare(<<<SQL
SELECT name FROM {$this->projectionTableName}
WHERE state = 'inline'
ORDER BY name
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

        $projector = $this->getProjector($projectorName);
        $transaction = $this->connection->beginTransaction();
        if ($transaction instanceof NoOpTransaction) {
            throw new \RuntimeException('catchupProjection should not be called inside a transaction');
        }
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
                $this->createSubscription($projectorName, new SubscriptionQuery(limit: 1000));

                $statement = $this->connection->prepare(<<<SQL
                    UPDATE {$this->projectionTableName}
                    SET state = 'catching_up'
                    WHERE name = ?
                    SQL);
                $statement->execute([$projectorName]);
            } else if ($projection['state'] !== 'catching_up') {
                throw new \RuntimeException('Projection is not in catchup or catching_up state');
            }

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
            // With this statement, we lock the event table for inserts, it should be quickly commited
            $maxIdStatement = $this->connection->prepare("SELECT MAX(id) FROM {$this->eventTableName} FOR UPDATE");
            $updateProjectionStatement = $this->connection->prepare(<<<SQL
                UPDATE {$this->projectionTableName}
                SET state = 'inline', after_event_id = ?
                WHERE name = ?
                SQL);
            $maxIdStatement->execute();
            $maxId = $maxIdStatement->fetchColumn();
            $updateProjectionStatement->execute([$maxId, $projectorName], [Statement::PARAM_INT]);

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }


        // Execute missing events
        $missingEventsLoop = 0;
        $stop = false;
        $toPosition = new LogEventId(0, $maxId);
        while (true) {
            $transaction = $this->connection->beginTransaction();
            try {
                $page = $this->readFromSubscription($projectorName, $toPosition);
                $lastPosition = $page->startPosition->sequenceNumber;
                foreach ($page->events as $event) {
                    $projector->project($event);
                    $lastPosition = $event->logEventId->sequenceNumber;
                }
                if ($lastPosition === $maxId) {
                    $this->deleteSubscription($projectorName);
                    $stop = true;
                } elseif ($page->events !== []) {
                    $this->ack($page);
                }

                $transaction->commit();
            } catch (\Throwable $e) {
                $transaction->rollBack();
                throw $e;
            }

            if ($stop) {
                break;
            }
            if ($missingEventsLoop < $missingEventsMaxLoops) {
                $missingEventsLoop++;
                \usleep(10000);
            } else {
                throw new \RuntimeException('Max missing events loop reached');
            }

        }
    }

    public function createSubscription(string $subscriptionName, SubscriptionQuery $subscriptionQuery): void
    {
        $this->ensureSchemaExists();

        $position = $subscriptionQuery->from ?? LogEventId::start();
        $this->connection->prepare(<<<SQL
            INSERT INTO {$this->subscriptionTableName} (event_id, name, query)
            VALUES (?, ?, ?)
            SQL)
            ->execute([
                $position->sequenceNumber, $subscriptionName, \json_encode($subscriptionQuery),
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

    public function readFromSubscription(string $subscriptionName, ?LogEventId $inlineTo = null): EventPage
    {
        $statement = $this->connection->prepare(<<<SQL
            SELECT event_id, query
            FROM {$this->subscriptionTableName}
            WHERE name = ?
            -- FOR UPDATE
            SQL);
        $statement->execute([$subscriptionName]);
        $row = $statement->fetch();
        if (!$row) {
            throw new \RuntimeException(\sprintf('Subscription "%s" not found', $subscriptionName));
        }
        $startPosition = new LogEventId(0, (int) $row['event_id']);
        $baseQueryData = \json_decode($row['query'], true);
        $to = $inlineTo ?? ($baseQueryData['to'] ? new LogEventId(0, $baseQueryData['to']['sequenceNumber']) : null);
        $baseQuery = new SubscriptionQuery(
            streamIds: $baseQueryData['streamIds'] ?? null,
            from: $startPosition,
            to: $to,
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
            SET event_id = ?
            WHERE name = ?
            SQL);
        $statement->execute([$page->endPosition->sequenceNumber, $page->subscriptionName]);
        if ($statement->rowCount() === 0) {
            throw new \RuntimeException(\sprintf('Subscription "%s" not found', $page->subscriptionName));
        }
    }

    public function schemaUp(): void
    {
        $this->connection->execute(<<<SQL
CREATE TABLE IF NOT EXISTS es_event
(
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stream_id      VARCHAR(255)    NOT NULL,
    version        INTEGER UNSIGNED NOT NULL,
    event_type     TEXT    NOT NULL,
    payload        JSON    NOT NULL,
    metadata       JSON    NOT NULL,
    UNIQUE (stream_id, version),
    INDEX (stream_id)
);

CREATE TABLE IF NOT EXISTS es_stream
(
    stream_id VARCHAR(255) NOT NULL PRIMARY KEY,
    version   INTEGER UNSIGNED NOT NULL
);

CREATE TABLE IF NOT EXISTS es_projection
(
    name                 VARCHAR(255) NOT NULL PRIMARY KEY,
    state                VARCHAR(255) NOT NULL,
    after_event_id       BIGINT UNSIGNED DEFAULT NULL,
    metadata             JSON DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS es_subscription
(
    name      VARCHAR(255)   NOT NULL PRIMARY KEY,
    event_id       BIGINT NOT NULL,
    query          JSON   NOT NULL
);
SQL);
    }

    public function schemaDown(): void
    {
        $this->connection->execute(<<<SQL
DROP TABLE IF EXISTS es_event;
    
DROP TABLE IF EXISTS es_stream;

DROP TABLE IF EXISTS es_projection;

DROP TABLE IF EXISTS es_subscription;
SQL);
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    protected function getProjector(string $projectorName): Projector
    {
        $projector = $this->projectors[$projectorName] ?? null;
        if (!$projector) {
            throw new \RuntimeException('Unknown projector ' . $projectorName);
        }
        return $projector;
    }

    protected function ensureSchemaExists(): void
    {
        if (!$this->schemaIsKnownToExists && $this->createSchema) {
            $transaction = $this->connection->beginTransaction();
            try {
                $wasInTransaction = $transaction instanceof NoOpTransaction;
                $statement = $this->connection->prepare("SELECT 1 FROM {$this->eventTableName} LIMIT 1");
                $statement->execute();
                $transaction->commit();
            } catch (DriverException $e) {
                $transaction->rollBack();
                if ($e->getCode() === self::MYSQL_ER_NO_SUCH_TABLE) {
                    if ($wasInTransaction) {
                        throw new \RuntimeException('ensureSchemaExists would have created the event store tables but it should not be called inside a transaction: the transaction would have been commited by DDL changes');
                    }
                    $this->schemaUp();
                }
            }

            $this->schemaIsKnownToExists = true;
        }
    }
}