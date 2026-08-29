<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Dbal;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Ecotone\Dbal\Connection\DbalContext;
use Ecotone\Dbal\DbalReconnectableConnectionFactory;
use Ecotone\Dbal\MultiTenant\MultiTenantConnectionFactory;
use Ecotone\EventSourcing\Dbal\WriteLock\MetadataLockStrategy;
use Ecotone\EventSourcing\Dbal\WriteLock\NoLockStrategy;
use Ecotone\EventSourcing\Dbal\WriteLock\PostgresAdvisoryLockStrategy;
use Ecotone\EventSourcing\Dbal\WriteLock\WriteLockStrategy;
use Ecotone\EventSourcing\EventStore;
use Ecotone\EventSourcing\EventStore\FieldType;
use Ecotone\EventSourcing\EventStore\MetadataMatcher;
use Ecotone\EventSourcing\EventStore\Operator;
use Ecotone\EventSourcing\Mapping\EventMapper;
use Ecotone\EventSourcing\StreamTableRegistry;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\Handler\TypeDefinitionException;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\ConcurrencyException;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Modelling\Event;
use Interop\Queue\ConnectionFactory;
use Ramsey\Uuid\Uuid;

use function array_key_exists;
use function count;
use function implode;
use function is_array;
use function is_bool;
use function is_int;
use function json_decode;
use function json_encode;

/**
 * licence BSD-3-Clause
 * code comes from https://github.com/prooph/pdo-event-store
 * (c) 2016-2025 Alexander Miertsch <kontakt@codeliner.ws>
 * (c) 2016-2025 Sascha-Oliver Prolic <saschaprolic@googlemail.com>
 */
final class DbalEventStore implements EventStore
{
    private const COLUMNS = ['event_id', 'event_name', 'payload', 'metadata', 'created_at'];

    /** @var array<string, bool> */
    private array $ensuredTables = [];

    /**
     * @param array<string, ConnectionFactory|null> $connectionFactories
     */
    public function __construct(
        private StreamTableRegistry $streamTableRegistry,
        private array $connectionFactories,
        private ConversionService $conversionService,
        private EventMapper $eventMapper,
        private int $loadBatchSize,
        private bool $enableWriteLockStrategy,
        private bool $automaticTableInitialization,
    ) {
    }

    public function create(string $streamName, array $streamEvents = [], array $streamMetadata = []): void
    {
        $this->ensureTableExists($streamName);

        if ($streamEvents !== []) {
            $this->appendTo($streamName, $streamEvents);
        }
    }

    public function appendTo(string $streamName, array $streamEvents): void
    {
        if ($streamEvents === []) {
            return;
        }

        if ($this->automaticTableInitialization) {
            $this->ensureTableExists($streamName);
        }

        $connection = $this->connectionFor($streamName);
        $schema = EventStreamSchemaFactory::for($connection);
        $tableName = $this->streamTableRegistry->tableFor($streamName);

        $rows = [];
        foreach ($streamEvents as $eventToConvert) {
            $rows[] = $this->convertToRow($eventToConvert);
        }

        $rowPlaces = '(' . implode(', ', array_fill(0, count(self::COLUMNS), '?')) . ')';
        $sql = 'INSERT INTO ' . $schema->quoteIdentifier($tableName) . ' (' . implode(', ', self::COLUMNS) . ') VALUES '
            . implode(', ', array_fill(0, count($rows), $rowPlaces));

        $parameters = [];
        foreach ($rows as $row) {
            foreach ($row as $value) {
                $parameters[] = $value;
            }
        }

        $lockStrategy = $this->writeLockStrategyFor($connection);
        $lockName = '_' . $tableName . '_write_lock';
        if (! $lockStrategy->getLock($connection, $lockName)) {
            throw new ConcurrencyException('Failed to acquire write lock for stream ' . $streamName);
        }

        try {
            $connection->executeStatement($sql, $parameters);
        } catch (UniqueConstraintViolationException $exception) {
            throw new ConcurrencyException($exception->getMessage(), $exception->getCode(), $exception);
        } finally {
            $lockStrategy->releaseLock($connection, $lockName);
        }
    }

    public function delete(string $streamName): void
    {
        $connection = $this->connectionFor($streamName);
        $schema = EventStreamSchemaFactory::for($connection);

        $connection->executeStatement($schema->dropTableSql($this->streamTableRegistry->tableFor($streamName)));
        unset($this->ensuredTables[$this->contextKeyFor($streamName)]);
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

        [$where, $parameters] = $this->createWhereClause($schema, $metadataMatcher);
        $where[] = 'no >= ?';
        $parameters[] = $fromNumber;

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
                $batchParameters
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

    public function ensureTableExists(string $streamName, ?\Throwable $previous = null): void
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
            throw new InvalidArgumentException(
                "Event stream table `{$tableName}` for stream `{$streamName}` does not exist. "
                . 'Run `ecotone:migration:database:setup` to create it.',
                0,
                $previous
            );
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
        if ($eventToConvert instanceof Event) {
            $payload = $eventToConvert->getPayload();
            $metadata = $eventToConvert->getMetadata();
        } else {
            $payload = $eventToConvert;
            $metadata = [];
            $eventToConvert = Event::create($payload);
        }

        $eventId = array_key_exists(MessageHeaders::MESSAGE_ID, $metadata)
            ? (string) $metadata[MessageHeaders::MESSAGE_ID]
            : Uuid::uuid4()->toString();
        $createdAt = array_key_exists(MessageHeaders::TIMESTAMP, $metadata)
            ? new DateTimeImmutable('@' . $metadata[MessageHeaders::TIMESTAMP], new DateTimeZone('UTC'))
            : new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $payloadAsArray = is_array($payload)
            ? $payload
            : $this->conversionService->convert($payload, Type::createFromVariable($payload), MediaType::createApplicationXPHP(), Type::array(), MediaType::createApplicationXPHP());

        return [
            $eventId,
            $this->eventMapper->mapEventToName($eventToConvert),
            json_encode($payloadAsArray, JSON_THROW_ON_ERROR),
            json_encode((object) $metadata, JSON_THROW_ON_ERROR),
            $createdAt->format('Y-m-d\TH:i:s.u'),
        ];
    }

    private function convertToEvent(array $row, bool $deserialize): Event
    {
        $eventType = null;
        try {
            $eventType = Type::create($this->eventMapper->mapNameToEventType($row['event_name']));
        } catch (TypeDefinitionException) {
        }

        $payload = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
        $metadata = json_decode($row['metadata'], true, 512, JSON_THROW_ON_ERROR) ?? [];

        return Event::createWithType(
            eventType: $eventType === null ? $row['event_name'] : $eventType->toString(),
            event: $deserialize && $eventType !== null
                ? $this->conversionService->convert($payload, Type::array(), MediaType::createApplicationXPHP(), $eventType, MediaType::createApplicationXPHP())
                : $payload,
            metadata: array_merge([MessageHeaders::REVISION => 1], $metadata)
        );
    }

    /**
     * @return array{0: array<string>, 1: array<mixed>}
     */
    private function createWhereClause(EventStreamSchema $schema, ?MetadataMatcher $metadataMatcher): array
    {
        $where = [];
        $parameters = [];

        if ($metadataMatcher === null) {
            return [$where, $parameters];
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
                }

                continue;
            }

            $where[] = "{$field} {$schema->operatorSql($operator)} ?";
            $parameters[] = $value;
        }

        return [$where, $parameters];
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

    private function writeLockStrategyFor(Connection $connection): WriteLockStrategy
    {
        if (! $this->enableWriteLockStrategy) {
            return new NoLockStrategy();
        }

        return $connection->getDatabasePlatform() instanceof PostgreSQLPlatform
            ? new PostgresAdvisoryLockStrategy()
            : new MetadataLockStrategy();
    }
}
