<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Dbal;

use function array_key_exists;
use function count;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Ecotone\Api\EventSourcing\AppendCondition;
use Ecotone\Api\EventSourcing\DecisionModelConcurrencyException;
use Ecotone\Dbal\Connection\DbalContext;
use Ecotone\Dbal\Database\MissingTableInstructions;
use Ecotone\Dbal\DbalReconnectableConnectionFactory;
use Ecotone\Dbal\MultiTenant\MultiTenantConnectionFactory;
use Ecotone\EventSourcing\Database\EventStreamTableManager;
use Ecotone\EventSourcing\Database\TagTableManager;
use Ecotone\EventSourcing\Dbal\Tag\TaggedEventSchema;
use Ecotone\EventSourcing\Dbal\Tag\TaggedEventSchemaFactory;
use Ecotone\EventSourcing\EventSerializer;
use Ecotone\EventSourcing\EventStore;
use Ecotone\EventSourcing\EventStore\AggregateEventStore;
use Ecotone\EventSourcing\EventStore\FieldType;
use Ecotone\EventSourcing\EventStore\MetadataMatcher;
use Ecotone\EventSourcing\EventStore\Operator;
use Ecotone\EventSourcing\StreamTableRegistry;
use Ecotone\EventSourcing\Tagging\EventTagRegistry;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\ConcurrencyException;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Modelling\Event;

use function implode;
use function is_object;
use function ksort;

use Interop\Queue\ConnectionFactory;

use function is_bool;
use function is_int;
use function json_decode;
use function json_encode;

use Ramsey\Uuid\Uuid;

/**
 * licence Apache-2.0
 */
final class DbalEventStore implements EventStore, AggregateEventStore
{
    private const COLUMNS = ['event_id', 'event_name', 'payload', 'metadata', 'created_at'];

    /** @var array<string, bool> */
    private array $ensuredTables = [];

    /** @var array<string, bool> */
    private array $ensuredTagTables = [];

    private EventTagRegistry $eventTagRegistry;

    /**
     * @param array<string, ConnectionFactory|null> $connectionFactories
     */
    public function __construct(
        private StreamTableRegistry $streamTableRegistry,
        private array $connectionFactories,
        private EventSerializer $eventSerializer,
        private int $loadBatchSize,
        private bool $automaticTableInitialization,
        private ?string $consoleInvocationPrefix = null,
        ?EventTagRegistry $eventTagRegistry = null,
    ) {
        $this->eventTagRegistry = $eventTagRegistry ?? EventTagRegistry::createEmpty();
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

        $connection = $this->connectionFor($streamName);
        $schema = EventStreamSchemaFactory::for($connection);
        $tableName = $this->streamTableRegistry->tableFor($streamName);

        $rows = [];
        $eventIds = [];
        $perEventTags = [];
        $tagsInvolved = [];

        foreach ($streamEvents as $eventToConvert) {
            $row = $this->convertToRow($eventToConvert);
            $rows[] = $row;
            $eventIds[] = $row[0];

            $tags = $this->resolveTags($eventToConvert);
            $perEventTags[] = $tags;
            foreach ($tags as $tag) {
                if ($this->eventTagRegistry->isFilterOnly($tag['name'])) {
                    continue;
                }

                $tagsInvolved[$this->tagKey($tag['name'], $tag['value'])] = $tag;
            }
        }

        $conditionTags = [];
        if ($appendCondition !== null) {
            foreach ($appendCondition->expectedTagVersions() as $expected) {
                $key = $this->tagKey($expected['name'], $expected['value']);
                $conditionTags[$key] = $expected;
                $tagsInvolved[$key] = ['name' => $expected['name'], 'value' => $expected['value']];
            }
        }

        if ($tagsInvolved === []) {
            $this->insertEventRows($connection, $schema, $tableName, $rows);

            return;
        }

        $this->ensureTagTablesExist($streamName, $connection);
        $tagSchema = TaggedEventSchemaFactory::for($connection);

        ksort($tagsInvolved);

        $write = function () use ($connection, $tagSchema, $schema, $tableName, $rows, $eventIds, $perEventTags, $tagsInvolved, $conditionTags): void {
            $newVersions = [];
            foreach ($tagsInvolved as $key => $tag) {
                $newVersions[$key] = isset($conditionTags[$key])
                    ? $this->bumpGuardedTagVersion($connection, $tagSchema, $tag['name'], $tag['value'], $conditionTags[$key]['expectedVersion'])
                    : $this->bumpUnconditionalTagVersion($connection, $tagSchema, $tag['name'], $tag['value']);
            }

            $this->insertEventRows($connection, $schema, $tableName, $rows);
            $this->insertTagIndexRows($connection, $tagSchema, $tableName, $eventIds, $perEventTags, $newVersions);
        };

        if ($connection->isTransactionActive()) {
            $write();
        } else {
            $connection->transactional($write);
        }
    }

    public function delete(string $streamName): void
    {
        $connection = $this->connectionFor($streamName);
        $schema = EventStreamSchemaFactory::for($connection);
        $tableName = $this->streamTableRegistry->tableFor($streamName);

        $tagSchema = TaggedEventSchemaFactory::for($connection);
        if ($tagSchema->tableExists($connection, TagTableManager::TAGGED_EVENTS_TABLE)) {
            $connection->executeStatement(
                'DELETE FROM ' . $tagSchema->quoteIdentifier(TagTableManager::TAGGED_EVENTS_TABLE) . ' WHERE stream_name = ?',
                [$tableName]
            );
        }

        $connection->executeStatement($schema->dropTableSql($tableName));
        unset($this->ensuredTables[$this->contextKeyFor($streamName)]);
    }

    private function tagKey(string $name, string $value): string
    {
        return $name . "\0" . $value;
    }

    /**
     * @return array<array{name: string, value: string}>
     */
    private function resolveTags(object|array $eventToConvert): array
    {
        $payload = $eventToConvert instanceof Event ? $eventToConvert->getPayload() : $eventToConvert;

        return is_object($payload) ? $this->eventTagRegistry->tagsFor($payload) : [];
    }

    /**
     * @param array<array{0: string, 1: string, 2: string, 3: string, 4: string}> $rows
     */
    private function insertEventRows(Connection $connection, EventStreamSchema $schema, string $tableName, array $rows): void
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
        }
    }

    private function bumpGuardedTagVersion(Connection $connection, TaggedEventSchema $tagSchema, string $name, string $value, int $capturedVersion): int
    {
        $versionsTable = $tagSchema->quoteIdentifier(TagTableManager::TAG_VERSIONS_TABLE);

        if ($capturedVersion === 0) {
            try {
                $affected = (int) $connection->executeStatement($tagSchema->insertInitialVersionSql(TagTableManager::TAG_VERSIONS_TABLE), [$name, $value]);
            } catch (UniqueConstraintViolationException) {
                $affected = 0;
            }

            if ($affected === 0) {
                throw DecisionModelConcurrencyException::forConflict($name, $value, $capturedVersion, $this->currentTagVersion($connection, $tagSchema, $name, $value));
            }

            return 1;
        }

        $affected = (int) $connection->executeStatement(
            "UPDATE {$versionsTable} SET version = version + 1 WHERE tag_name = ? AND tag_value = ? AND version = ?",
            [$name, $value, $capturedVersion]
        );

        if ($affected === 0) {
            throw DecisionModelConcurrencyException::forConflict($name, $value, $capturedVersion, $this->currentTagVersion($connection, $tagSchema, $name, $value));
        }

        return $capturedVersion + 1;
    }

    private function bumpUnconditionalTagVersion(Connection $connection, TaggedEventSchema $tagSchema, string $name, string $value): int
    {
        $sql = $tagSchema->upsertIncrementVersionSql(TagTableManager::TAG_VERSIONS_TABLE);

        if ($tagSchema->supportsReturningOnUpsert()) {
            return (int) $connection->executeQuery($sql, [$name, $value])->fetchOne();
        }

        $connection->executeStatement($sql, [$name, $value]);

        return $this->currentTagVersion($connection, $tagSchema, $name, $value);
    }

    private function currentTagVersion(Connection $connection, TaggedEventSchema $tagSchema, string $name, string $value): int
    {
        $versionsTable = $tagSchema->quoteIdentifier(TagTableManager::TAG_VERSIONS_TABLE);

        return (int) $connection->executeQuery(
            "SELECT version FROM {$versionsTable} WHERE tag_name = ? AND tag_value = ?",
            [$name, $value]
        )->fetchOne();
    }

    /**
     * @param string[] $eventIds
     * @param array<array<array{name: string, value: string}>> $perEventTags
     * @param array<string, int> $newVersions
     */
    private function insertTagIndexRows(Connection $connection, TaggedEventSchema $tagSchema, string $tableName, array $eventIds, array $perEventTags, array $newVersions): void
    {
        $selects = [];
        $parameters = [];

        foreach ($eventIds as $i => $eventId) {
            foreach ($perEventTags[$i] as $tag) {
                $tagSequence = $this->eventTagRegistry->isFilterOnly($tag['name']) ? 0 : $newVersions[$this->tagKey($tag['name'], $tag['value'])];

                $selects[] = 'SELECT ? AS tag_name, ? AS tag_value, ? AS stream_name, s.no AS event_no, ? AS tag_sequence FROM '
                    . $tagSchema->quoteIdentifier($tableName) . ' s WHERE s.event_id = ?';
                $parameters[] = $tag['name'];
                $parameters[] = $tag['value'];
                $parameters[] = $tableName;
                $parameters[] = $tagSequence;
                $parameters[] = $eventId;
            }
        }

        if ($selects === []) {
            return;
        }

        $indexTable = $tagSchema->quoteIdentifier(TagTableManager::TAGGED_EVENTS_TABLE);
        $sql = "INSERT INTO {$indexTable} (tag_name, tag_value, stream_name, event_no, tag_sequence) " . implode(' UNION ALL ', $selects);

        $connection->executeStatement($sql, $parameters);
    }

    private function ensureTagTablesExist(string $streamName, Connection $connection): void
    {
        $contextKey = $this->tagTableContextKeyFor($streamName);
        if (isset($this->ensuredTagTables[$contextKey])) {
            return;
        }

        $tagSchema = TaggedEventSchemaFactory::for($connection);
        if ($tagSchema->tableExists($connection, TagTableManager::TAGGED_EVENTS_TABLE)
            && $tagSchema->tableExists($connection, TagTableManager::TAG_VERSIONS_TABLE)) {
            $this->ensuredTagTables[$contextKey] = true;

            return;
        }

        if (! $this->automaticTableInitialization) {
            throw ConfigurationException::create(MissingTableInstructions::build(
                TagTableManager::FEATURE_NAME,
                TagTableManager::TAGGED_EVENTS_TABLE . ', ' . TagTableManager::TAG_VERSIONS_TABLE,
                $this->consoleInvocationPrefix
            ));
        }

        foreach ($tagSchema->createTaggedEventsTableSql(TagTableManager::TAGGED_EVENTS_TABLE) as $statement) {
            $connection->executeStatement($statement);
        }
        foreach ($tagSchema->createTagVersionsTableSql(TagTableManager::TAG_VERSIONS_TABLE) as $statement) {
            $connection->executeStatement($statement);
        }

        $this->ensuredTagTables[$contextKey] = true;
    }

    private function tagTableContextKeyFor(string $streamName): string
    {
        $connectionReference = $this->streamTableRegistry->connectionReferenceFor($streamName);
        $connectionFactory = $this->connectionFactories[$connectionReference] ?? null;
        $tenant = $connectionFactory instanceof MultiTenantConnectionFactory ? $connectionFactory->currentActiveTenant() : 'default';

        return $connectionReference . '|' . $tenant;
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

        if (! $schema->tableExists($connection, $tableName)) {
            return [];
        }

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

        if (! $schema->tableExists($connection, $tableName)) {
            return [];
        }

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

        return $this->selectEvents($connection, $schema, $tableName, $where, $parameters, $types, 1, $count, $deserialize);
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
            $limit = $remaining === null ? $this->loadBatchSize : min($remaining, $this->loadBatchSize);
            $batchParameters = $parameters;
            $batchParameters[count($batchParameters) - 1] = $position;

            $rows = $connection->executeQuery(
                'SELECT no, event_name, payload, metadata FROM ' . $schema->quoteIdentifier($tableName)
                . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY no ASC LIMIT ' . $limit,
                $batchParameters,
                $types
            )->fetchAllAssociative();

            foreach ($rows as $row) {
                $events[] = $this->convertToEvent($row, $deserialize);
                $position = ((int) $row['no']) + 1;
            }

            if (count($rows) < $limit) {
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

        if (! $this->automaticTableInitialization) {
            throw ConfigurationException::create(MissingTableInstructions::build(EventStreamTableManager::FEATURE_NAME, $tableName, $this->consoleInvocationPrefix));
        }

        foreach (EventStreamSchemaFactory::for($connection)->createTableSql($tableName) as $statement) {
            $connection->executeStatement($statement);
        }
    }

    private function contextKeyFor(string $streamName): string
    {
        $connectionReference = $this->streamTableRegistry->connectionReferenceFor($streamName);
        $connectionFactory = $this->connectionFactories[$connectionReference] ?? null;
        $tenant = $connectionFactory instanceof MultiTenantConnectionFactory ? $connectionFactory->currentActiveTenant() : 'default';

        return $connectionReference . '|' . $tenant . '|' . $streamName;
    }

    public function getConnectionForStream(string $streamName): Connection
    {
        return $this->connectionFor($streamName);
    }

    private function convertToRow(object|array $eventToConvert): array
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

    private function convertToEvent(array $row, bool $deserialize): Event
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
