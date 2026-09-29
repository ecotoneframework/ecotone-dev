<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Dbal;

use function array_key_exists;
use function array_pop;
use function count;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverExceptionInterface;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Ecotone\Api\EventSourcing\AppendCondition;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\EventSourcing\LoadedEvents;
use Ecotone\Dbal\Connection\DbalContext;
use Ecotone\Dbal\Database\AutomaticTableInitializationSupport;
use Ecotone\Dbal\DbalReconnectableConnectionFactory;
use Ecotone\Dbal\MultiTenant\MultiTenantConnectionFactory;
use Ecotone\EventSourcing\Database\MissingEventStreamTable;
use Ecotone\EventSourcing\Dbal\Tag\DbalTagCollaborator;
use Ecotone\EventSourcing\Dbal\Tag\TagBackfillReport;
use Ecotone\EventSourcing\EventSerializer;
use Ecotone\EventSourcing\EventStore;
use Ecotone\EventSourcing\EventStore\AppendStrategy\AppendableStore;
use Ecotone\EventSourcing\EventStore\AppendStrategy\AppendStrategy;
use Ecotone\EventSourcing\EventStore\FieldType;
use Ecotone\EventSourcing\EventStore\GuardedTagBump;
use Ecotone\EventSourcing\EventStore\MetadataMatcher;
use Ecotone\EventSourcing\EventStore\Operator;
use Ecotone\EventSourcing\Projecting\ProjectionInvariantGuard;
use Ecotone\EventSourcing\StreamTableRegistry;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\ConcurrencyException;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Modelling\Event;

use function implode;

use Interop\Queue\ConnectionFactory;

use function is_bool;
use function is_int;
use function json_decode;
use function json_encode;

use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * licence Apache-2.0
 */
final class DbalEventStore implements EventStore, AppendableStore, GuardedTagBump
{
    private const COLUMNS = ['event_id', 'event_name', 'payload', 'metadata', 'created_at'];

    /** @var array<string, bool> */
    private array $ensuredTables = [];

    private AppendStrategy $appendStrategy;

    private DbalTagCollaborator $tagCollaborator;

    /**
     * @param array<string, ConnectionFactory|null> $connectionFactories
     */
    public function __construct(
        private StreamTableRegistry $streamTableRegistry,
        private array $connectionFactories,
        private EventSerializer $eventSerializer,
        private int $loadBatchSize,
        private bool $automaticTableInitialization,
        DbalTagCollaborator $tagCollaborator,
        private ProjectionInvariantGuard $projectionInvariantGuard,
        AppendStrategy $appendStrategy,
        private MissingEventStreamTable $missingEventStreamTable,
        private ?string $consoleInvocationPrefix = null,
    ) {
        $this->tagCollaborator = $tagCollaborator;
        $this->appendStrategy = $appendStrategy;
    }

    public function create(string $streamName, array $streamEvents = [], array $streamMetadata = []): void
    {
        $this->ensureTableExists($streamName);

        if ($streamEvents !== []) {
            $this->appendTo($streamName, $streamEvents);
        }
    }

    public function appendTo(string $streamName, array $streamEvents, ?AppendCondition $appendCondition = null): void
    {
        if ($streamEvents === []) {
            return;
        }

        $this->ensureTableExists($streamName);

        $this->appendStrategy->append($this, $streamName, $streamEvents, $appendCondition);
    }

    public function appendEventsUnconditionally(string $streamName, array $events): void
    {
        $connection = $this->connectionFor($streamName);
        $schema = EventStreamSchemaFactory::for($connection);
        $tableName = $this->streamTableRegistry->tableFor($streamName);

        $this->insertEventRows($connection, $schema, $tableName, $this->rowsToAppend($streamName, $events));
    }

    /**
     * @param object[]|array[] $events
     * @return array<array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    public function rowsToAppend(string $streamName, array $events): array
    {
        $rows = [];
        foreach ($events as $eventToConvert) {
            $row = $this->convertToRow($eventToConvert);
            $this->assertProjectionInvariant($streamName, $row[1], $eventToConvert);
            $rows[] = $row;
        }

        return $rows;
    }

    public function appendEventsWithAggregateCondition(string $streamName, array $events, AppendCondition $appendCondition): void
    {
        $this->appendEventsUnconditionally($streamName, $events);
    }

    public function appendEventsWithTagCondition(string $streamName, array $events, ?AppendCondition $appendCondition): void
    {
        $connection = $this->connectionFor($streamName);
        $schema = EventStreamSchemaFactory::for($connection);
        $tableName = $this->streamTableRegistry->tableFor($streamName);

        $this->tagCollaborator->appendEventsWithTagCondition($this, $connection, $schema, $tableName, $streamName, $events, $appendCondition);
    }

    public function delete(string $streamName): void
    {
        $connection = $this->connectionFor($streamName);
        $schema = EventStreamSchemaFactory::for($connection);
        $tableName = $this->streamTableRegistry->tableFor($streamName);

        $this->tagCollaborator->deleteTagIndexFor($connection, $tableName);

        $connection->executeStatement($schema->dropTableSql($tableName));
        unset($this->ensuredTables[$this->contextKeyFor($streamName)]);
    }

    public function loadByCriteria(EventCriteria $criteria): LoadedEvents
    {
        $connection = $this->connectionFor(StreamTableRegistry::DEFAULT_STREAM);

        return $this->tagCollaborator->loadByCriteria($this, $connection, $criteria);
    }

    public function bumpTagsGuarded(AppendCondition $appendCondition): void
    {
        $this->tagCollaborator->bumpTagsGuarded($this, $this->connectionFor(StreamTableRegistry::DEFAULT_STREAM), $appendCondition);
    }

    public function backfillTagsForStream(
        string $streamName,
        ?string $onlyEventName,
        ?int $fromNo,
        int $batchSize,
        bool $dryRun,
        bool $skipUndeserializable,
        TagBackfillReport $report,
    ): void {
        $connection = $this->connectionFor($streamName);
        $schema = EventStreamSchemaFactory::for($connection);
        $tableName = $this->streamTableRegistry->tableFor($streamName);

        $this->tagCollaborator->backfillTagsForStream($this, $connection, $schema, $tableName, $streamName, $onlyEventName, $fromNo, $batchSize, $dryRun, $skipUndeserializable, $report);
    }

    /**
     * @param int[] $eventNos
     * @return array<int, Event>
     */
    public function loadEventsByNumbers(Connection $connection, string $tableName, array $eventNos): array
    {
        $placeholders = implode(', ', array_fill(0, count($eventNos), '?'));

        $rows = $connection->executeQuery(
            'SELECT no, event_name, payload, metadata FROM ' . EventStreamSchemaFactory::for($connection)->quoteIdentifier($tableName) . " WHERE no IN ({$placeholders})",
            $eventNos,
            array_fill(0, count($eventNos), ParameterType::INTEGER)
        )->fetchAllAssociative();

        $events = [];
        foreach ($rows as $row) {
            $events[(int) $row['no']] = $this->convertToEvent($row, true);
        }

        return $events;
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function loadRowBatch(Connection $connection, EventStreamSchema $schema, string $tableName, int $fromNo, ?string $onlyEventName, int $limit): array
    {
        $where = ['no >= ?'];
        $parameters = [$fromNo];
        $types = [ParameterType::INTEGER];
        if ($onlyEventName !== null) {
            $where[] = 'event_name = ?';
            $parameters[] = $onlyEventName;
            $types[] = ParameterType::STRING;
        }

        return $connection->executeQuery(
            'SELECT no, event_id, event_name, payload, metadata FROM ' . $schema->quoteIdentifier($tableName)
            . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY no ASC LIMIT ' . $limit,
            $parameters,
            $types
        )->fetchAllAssociative();
    }

    /**
     * @param array<array{0: string, 1: string, 2: string, 3: string, 4: string}> $rows
     */
    public function insertEventRows(Connection $connection, EventStreamSchema $schema, string $tableName, array $rows): void
    {
        $rowPlaces = '(' . implode(', ', array_fill(0, count(self::COLUMNS), '?')) . ')';
        $sql = 'INSERT INTO ' . $schema->quoteIdentifier($tableName) . ' (' . implode(', ', self::COLUMNS) . ') VALUES '
            . implode(', ', array_fill(0, count($rows), $rowPlaces));

        $parameters = [];
        foreach ($rows as $row) {
            foreach ($row as $value) {
                $parameters[] = $value;
            }
        }

        try {
            $connection->executeStatement($sql, $parameters);
        } catch (UniqueConstraintViolationException $exception) {
            throw new ConcurrencyException($exception->getMessage(), $exception->getCode(), $exception);
        } catch (NotNullConstraintViolationException $exception) {
            throw $this->legacyAggregateConstraintException($connection, $tableName, $exception);
        } catch (DriverExceptionInterface $exception) {
            if ($exception->getSQLState() === '23514') {
                throw $this->legacyAggregateConstraintException($connection, $tableName, $exception);
            }

            throw $exception;
        }
    }

    private function legacyAggregateConstraintException(Connection $connection, string $tableName, Throwable $previous): ConfigurationException
    {
        // The insert failed mid-transaction; PostgreSQL refuses further statements once a transaction is aborted,
        // so the fix is built from the platform and the known 1.x constraint names rather than a live re-query.
        $platform = $connection->getDatabasePlatform();

        $fix = $platform instanceof PostgreSQLPlatform
            ? sprintf(
                'SET lock_timeout = \'2s\'; ALTER TABLE "%s" DROP CONSTRAINT IF EXISTS aggregate_version_not_null, '
                . 'DROP CONSTRAINT IF EXISTS aggregate_type_not_null, DROP CONSTRAINT IF EXISTS aggregate_id_not_null;',
                $tableName,
            )
            : sprintf(
                'MODIFY each generated aggregate column (aggregate_version, aggregate_type, aggregate_id) on `%s` '
                . 'without NOT NULL, restating its expression.',
                $tableName,
            );

        return ConfigurationException::create(sprintf(
            "An event with no aggregate metadata could not be appended to '%s' -- this stream still enforces its "
            . '1.x NOT NULL constraints on the aggregate columns, which reject an aggregate-less decision-model '
            . "event. Fix:\n%s\n\n(Driver message: %s)",
            $tableName,
            $fix,
            $previous->getMessage(),
        ));
    }

    public function hasStream(string $streamName): bool
    {
        $connection = $this->connectionFor($streamName);

        return EventStreamSchemaFactory::for($connection)->tableExists($connection, $this->streamTableRegistry->tableFor($streamName));
    }

    public function load(
        string $streamName,
        int $fromNumber = 1,
        ?int $count = null,
        ?MetadataMatcher $metadataMatcher = null,
        bool $deserialize = true
    ): iterable {
        if ($fromNumber < 1) {
            throw new InvalidArgumentException('fromNumber must be >= 1');
        }

        $connection = $this->connectionFor($streamName);
        $schema = EventStreamSchemaFactory::for($connection);
        $tableName = $this->streamTableRegistry->tableFor($streamName);

        [$where, $parameters, $types] = $this->createWhereClause($schema, $metadataMatcher);

        return $this->selectEvents($connection, $schema, $tableName, $where, $parameters, $types, $fromNumber, $count, $deserialize);
    }

    /**
     * @param string[] $eventNames
     */
    public function loadAggregateEvents(
        string $streamName,
        ?string $aggregateType,
        string $aggregateId,
        int $fromVersion = 1,
        ?int $count = null,
        array $eventNames = [],
        bool $deserialize = true
    ): iterable {
        if ($fromVersion < 1) {
            throw new InvalidArgumentException('fromVersion must be >= 1');
        }

        $connection = $this->connectionFor($streamName);
        $schema = EventStreamSchemaFactory::for($connection);
        $tableName = $this->streamTableRegistry->tableFor($streamName);

        [$where, $parameters, $types] = $this->createAggregateWhereClause($schema, $aggregateType, $aggregateId, $fromVersion, $eventNames);

        return $this->selectEvents($connection, $schema, $tableName, $where, $parameters, $types, 1, $count, $deserialize);
    }

    /**
     * @param string[] $eventNames
     * @return Event[]
     */

    public function missingStreamTableException(Connection $connection, string $tableName, ?string $streamName = null): ConfigurationException
    {
        return $this->missingEventStreamTable->exceptionFor(
            $connection,
            $tableName,
            $streamName === null ? null : $this->streamTableRegistry->connectionReferenceFor($streamName),
        );
    }

    /**
     * @param string[] $eventNames
     * @return array{0: array<string>, 1: array<mixed>, 2: array<ParameterType>}
     */
    private function createAggregateWhereClause(EventStreamSchema $schema, ?string $aggregateType, string $aggregateId, int $fromVersion, array $eventNames): array
    {
        $where = [];
        $parameters = [];
        $types = [];

        if ($aggregateType !== null) {
            $where[] = $schema->metadataFieldExpression(MessageHeaders::EVENT_AGGREGATE_TYPE, false) . ' = ?';
            $parameters[] = $aggregateType;
            $types[] = ParameterType::STRING;
        }

        $where[] = $schema->metadataFieldExpression(MessageHeaders::EVENT_AGGREGATE_ID, false) . ' = ?';
        $parameters[] = $aggregateId;
        $types[] = ParameterType::STRING;

        $where[] = $schema->metadataFieldExpression(MessageHeaders::EVENT_AGGREGATE_VERSION, true) . ' >= ?';
        $parameters[] = $fromVersion;
        $types[] = ParameterType::INTEGER;

        if ($eventNames !== []) {
            $placeholders = implode(', ', array_fill(0, count($eventNames), '?'));
            $where[] = "event_name IN ({$placeholders})";
            foreach ($eventNames as $eventName) {
                $parameters[] = $eventName;
                $types[] = ParameterType::STRING;
            }
        }

        return [$where, $parameters, $types];
    }

    /**
     * @param array<string> $where
     * @param array<mixed> $parameters
     * @param array<ParameterType> $types
     * @return Event[]
     */
    private function selectEvents(Connection $connection, EventStreamSchema $schema, string $tableName, array $where, array $parameters, array $types, int $fromNumber, ?int $count, bool $deserialize): array
    {
        $where[] = 'no >= ?';
        $parameters[] = $fromNumber;
        $types[] = ParameterType::INTEGER;

        $events = [];
        $position = $fromNumber;
        $remaining = $count;

        while ($remaining === null || $remaining > 0) {
            $pageSize = $remaining === null ? $this->loadBatchSize : min($remaining, $this->loadBatchSize);
            $batchParameters = $parameters;
            $batchParameters[count($batchParameters) - 1] = $position;

            try {
                $rows = $connection->executeQuery(
                    'SELECT no, event_name, payload, metadata FROM ' . $schema->quoteIdentifier($tableName)
                    . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY no ASC LIMIT ' . ($pageSize + 1),
                    $batchParameters,
                    $types
                )->fetchAllAssociative();
            } catch (TableNotFoundException) {
                throw $this->missingStreamTableException($connection, $tableName);
            }

            $furtherEventsFollowThisPage = count($rows) > $pageSize;
            if ($furtherEventsFollowThisPage) {
                array_pop($rows);
            }

            foreach ($rows as $row) {
                $events[] = $this->convertToEvent($row, $deserialize);
                $position = ((int) $row['no']) + 1;
            }

            if (! $furtherEventsFollowThisPage) {
                break;
            }

            if ($remaining !== null) {
                $remaining -= count($rows);
            }
        }

        return $events;
    }

    public function ensureTableExists(string $streamName): void
    {
        $contextKey = $this->contextKeyFor($streamName);
        if (isset($this->ensuredTables[$contextKey])) {
            return;
        }

        $connection = $this->connectionFor($streamName);
        $tableName = $this->streamTableRegistry->tableFor($streamName);

        if (EventStreamSchemaFactory::for($connection)->tableExists($connection, $tableName)) {
            $this->ensuredTables[$contextKey] = true;

            return;
        }

        if (! $this->automaticTableInitialization || ! AutomaticTableInitializationSupport::isSupported($connection)) {
            throw $this->missingStreamTableException($connection, $tableName, $streamName);
        }

        foreach (EventStreamSchemaFactory::for($connection)->createTableSql($tableName) as $statement) {
            $connection->executeStatement($statement);
        }

        $this->ensuredTables[$contextKey] = true;
    }

    private function contextKeyFor(string $streamName): string
    {
        $connectionReference = $this->streamTableRegistry->connectionReferenceFor($streamName);
        $connectionFactory = $this->connectionFactories[$connectionReference] ?? null;
        $tenant = $connectionFactory instanceof MultiTenantConnectionFactory ? $connectionFactory->currentActiveTenant() : 'default';

        return $connectionReference . '|' . $tenant . '|' . $streamName;
    }

    public function tagTableContextKeyFor(string $streamName): string
    {
        $connectionReference = $this->streamTableRegistry->connectionReferenceFor($streamName);
        $connectionFactory = $this->connectionFactories[$connectionReference] ?? null;
        $tenant = $connectionFactory instanceof MultiTenantConnectionFactory ? $connectionFactory->currentActiveTenant() : 'default';

        return $connectionReference . '|' . $tenant;
    }

    public function isAutomaticTableInitializationEnabled(): bool
    {
        return $this->automaticTableInitialization;
    }

    public function consoleInvocationPrefix(): ?string
    {
        return $this->consoleInvocationPrefix;
    }

    public function getConnectionForStream(string $streamName): Connection
    {
        return $this->connectionFor($streamName);
    }

    public function assertProjectionInvariant(string $streamName, string $eventName, object|array $eventToConvert): void
    {
        $metadata = $eventToConvert instanceof Event ? $eventToConvert->getMetadata() : [];
        if (array_key_exists(MessageHeaders::EVENT_AGGREGATE_ID, $metadata)) {
            return;
        }

        $projectionName = $this->projectionInvariantGuard->projectionGuardingAggregatelessEvent($streamName, $eventName);
        if ($projectionName === null) {
            return;
        }

        throw ConfigurationException::create(
            "Cannot append event {$eventName} without an aggregate to stream '{$streamName}': "
            . "projection '{$projectionName}' is registered with #[Partitioned] or #[FromAggregateStream], "
            . 'and reads this stream filtered by aggregate type, so it would never see this event. '
            . 'Use #[FromStream] on a global projection instead if it also needs to handle aggregate-less events on this stream.'
        );
    }

    public function convertToRow(object|array $eventToConvert): array
    {
        $metadata = $eventToConvert instanceof Event ? $eventToConvert->getMetadata() : [];
        $serialized = $this->eventSerializer->serialize($eventToConvert);

        $eventId = array_key_exists(MessageHeaders::MESSAGE_ID, $metadata)
            ? (string) $metadata[MessageHeaders::MESSAGE_ID]
            : Uuid::uuid4()->toString();
        $createdAt = array_key_exists(MessageHeaders::TIMESTAMP, $metadata)
            ? new DateTimeImmutable('@' . $metadata[MessageHeaders::TIMESTAMP], new DateTimeZone('UTC'))
            : new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return [
            $eventId,
            $serialized->getEventName(),
            json_encode($serialized->getPayload(), JSON_THROW_ON_ERROR),
            json_encode((object) $metadata, JSON_THROW_ON_ERROR),
            $createdAt->format('Y-m-d\TH:i:s.u'),
        ];
    }

    public function convertToEvent(array $row, bool $deserialize): Event
    {
        return $this->eventSerializer->deserialize(
            $row['event_name'],
            json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR),
            json_decode($row['metadata'], true, 512, JSON_THROW_ON_ERROR) ?? [],
            $deserialize
        );
    }

    /**
     * @return array{0: array<string>, 1: array<mixed>, 2: array<ParameterType>}
     */
    private function createWhereClause(EventStreamSchema $schema, ?MetadataMatcher $metadataMatcher): array
    {
        $where = [];
        $parameters = [];
        $types = [];

        if ($metadataMatcher === null) {
            return [$where, $parameters, $types];
        }

        foreach ($metadataMatcher->data() as $match) {
            /** @var FieldType $fieldType */
            $fieldType = $match['fieldType'];
            /** @var Operator $operator */
            $operator = $match['operator'];
            $value = $match['value'];

            $field = $fieldType === FieldType::METADATA
                ? $schema->metadataFieldExpression($match['field'], is_int($value))
                : $match['field'];

            if (is_bool($value)) {
                $where[] = "{$field} {$schema->operatorSql($operator)} {$schema->booleanLiteral($value)}";

                continue;
            }

            if ($operator === Operator::IN || $operator === Operator::NOT_IN) {
                if ($value === []) {
                    $where[] = $operator === Operator::IN ? '1 = 0' : '1 = 1';

                    continue;
                }

                $placeholders = implode(', ', array_fill(0, count($value), '?'));
                $where[] = $operator === Operator::IN
                    ? "{$field} IN ({$placeholders})"
                    : "{$field} NOT IN ({$placeholders})";
                foreach ($value as $singleValue) {
                    $parameters[] = $singleValue;
                    $types[] = is_int($singleValue) ? ParameterType::INTEGER : ParameterType::STRING;
                }

                continue;
            }

            $where[] = "{$field} {$schema->operatorSql($operator)} ?";
            $parameters[] = $value;
            $types[] = is_int($value) ? ParameterType::INTEGER : ParameterType::STRING;
        }

        return [$where, $parameters, $types];
    }

    private function connectionFor(string $streamName): Connection
    {
        $connectionReference = $this->streamTableRegistry->connectionReferenceFor($streamName);
        $connectionFactory = $this->connectionFactories[$connectionReference] ?? null;

        if ($connectionFactory === null) {
            throw new InvalidArgumentException("Connection `{$connectionReference}` used by event stream `{$streamName}` is not registered");
        }

        /** @var DbalContext $context */
        $context = (new DbalReconnectableConnectionFactory($connectionFactory))->createContext();

        return $context->getDbalConnection();
    }
}
