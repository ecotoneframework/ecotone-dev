<?php

namespace Ecotone\EventSourcing\Prooph;

use Doctrine\DBAL\Driver\PDOConnection;
use Ecotone\Dbal\Compatibility\SchemaManagerCompatibility;
use Ecotone\Dbal\DbalReconnectableConnectionFactory;
use Ecotone\Dbal\MultiTenant\MultiTenantConnectionFactory;
use Ecotone\EventSourcing\EventSourcingConfiguration;
use Ecotone\EventSourcing\Prooph\PersistenceStrategy\InterlopMariaDbSimpleStreamStrategy;
use Ecotone\EventSourcing\Prooph\PersistenceStrategy\InterlopMysqlSimpleStreamStrategy;
use Ecotone\EventSourcing\ProophEventMapper;
use Ecotone\Messaging\Support\ConcurrencyException;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Interop\Queue\ConnectionFactory;
use Iterator;
use PDO;
use Prooph\Common\Messaging\MessageConverter;
use Prooph\EventStore\EventStore;
use Prooph\EventStore\Exception\ConcurrencyException as ProophConcurrencyException;
use Prooph\EventStore\Exception\StreamNotFound;
use Prooph\EventStore\Metadata\MetadataMatcher;
use Prooph\EventStore\Pdo\MariaDbEventStore;
use Prooph\EventStore\Pdo\MySqlEventStore;
use Prooph\EventStore\Pdo\PersistenceStrategy;
use Prooph\EventStore\Pdo\PostgresEventStore;
use Prooph\EventStore\Pdo\WriteLockStrategy\MariaDbMetadataLockStrategy;
use Prooph\EventStore\Pdo\WriteLockStrategy\MysqlMetadataLockStrategy;
use Prooph\EventStore\Pdo\WriteLockStrategy\NoLockStrategy;
use Prooph\EventStore\Pdo\WriteLockStrategy\PostgresAdvisoryLockStrategy;
use Prooph\EventStore\Stream;
use Prooph\EventStore\StreamName;

use function str_contains;

use Throwable;

/**
 * licence Apache-2.0
 */
class LazyProophEventStore implements EventStore
{
    public const DEFAULT_ENABLE_WRITE_LOCK_STRATEGY = false;
    public const INITIALIZE_ON_STARTUP = true;
    public const LOAD_BATCH_SIZE = 1000;

    public const DEFAULT_STREAM_TABLE = 'event_streams';
    public const DEFAULT_PROJECTIONS_TABLE = 'projections';

    public const EVENT_STORE_TYPE_MYSQL = 'mysql';
    public const EVENT_STORE_TYPE_POSTGRES = 'postgres';
    public const EVENT_STORE_TYPE_MARIADB = 'mariadb';
    public const EVENT_STORE_TYPE_IN_MEMORY = 'inMemory';

    /**
     * Partition persistence strategy
     * @deprecated Ecotone 2.0 will be removed in favour of partition stream persistence strategy
     */
    public const SINGLE_STREAM_PERSISTENCE = 'single';
    /**
     * Same as single stream strategy.
     */
    public const PARTITION_STREAM_PERSISTENCE = 'partition';
    public const AGGREGATE_STREAM_PERSISTENCE = 'aggregate';
    public const SIMPLE_STREAM_PERSISTENCE = 'simple';
    public const CUSTOM_STREAM_PERSISTENCE = 'custom';

    public const AGGREGATE_VERSION = '_aggregate_version';
    public const AGGREGATE_TYPE = '_aggregate_type';
    public const AGGREGATE_ID = '_aggregate_id';
    public const PERSISTENCE_STRATEGY_METADATA = '_persistence';

    /** @var EventStore[] */
    private array $initializedEventStore = [];
    private MessageConverter $messageConverter;
    /** @var array<string, bool> */
    private array $initializated = [];
    private bool $canBeInitialized;
    private array $ensuredExistingStreams = [];

    public function __construct(
        private EventSourcingConfiguration $eventSourcingConfiguration,
        private ProophEventMapper          $messageFactory,
        private ConnectionFactory|null     $connectionFactory,
    ) {
        $this->messageConverter = new FromProophMessageToArrayConverter();
        $this->canBeInitialized = $eventSourcingConfiguration->isInitializedOnStart();
    }

    public function fetchStreamMetadata(StreamName $streamName): array
    {
        return $this->getEventStore($streamName)->fetchStreamMetadata($streamName);
    }

    public function hasStream(StreamName $streamName): bool
    {
        return $this->getEventStore($streamName)->hasStream($streamName);
    }

    public function load(StreamName $streamName, int $fromNumber = 1, ?int $count = null, ?MetadataMatcher $metadataMatcher = null): Iterator
    {
        return $this->getEventStore($streamName)->load($streamName, $fromNumber, $count, $metadataMatcher);
    }

    public function loadReverse(StreamName $streamName, ?int $fromNumber = null, ?int $count = null, ?MetadataMatcher $metadataMatcher = null): Iterator
    {
        return $this->getEventStore($streamName)->loadReverse($streamName, $fromNumber, $count, $metadataMatcher);
    }

    public function fetchStreamNames(?string $filter, ?MetadataMatcher $metadataMatcher, int $limit = 20, int $offset = 0): array
    {
        return $this->getEventStore()->fetchStreamNames($filter, $metadataMatcher, $limit, $offset);
    }

    public function fetchStreamNamesRegex(string $filter, ?MetadataMatcher $metadataMatcher, int $limit = 20, int $offset = 0): array
    {
        return $this->getEventStore()->fetchStreamNamesRegex($filter, $metadataMatcher, $limit, $offset);
    }

    public function fetchCategoryNames(?string $filter, int $limit = 20, int $offset = 0): array
    {
        return $this->getEventStore()->fetchCategoryNames($filter, $limit, $offset);
    }

    public function fetchCategoryNamesRegex(string $filter, int $limit = 20, int $offset = 0): array
    {
        return $this->getEventStore()->fetchCategoryNamesRegex($filter, $limit, $offset);
    }

    public function updateStreamMetadata(StreamName $streamName, array $newMetadata): void
    {
        $this->getEventStore($streamName)->updateStreamMetadata($streamName, $newMetadata);
    }

    public function create(Stream $stream): void
    {
        try {
            $this->getEventStore($stream->streamName(), $stream->metadata()[self::PERSISTENCE_STRATEGY_METADATA] ?? null)->create($stream);
        } catch (ProophConcurrencyException $exception) {
            throw new ConcurrencyException($exception->getMessage(), $exception->getCode(), $exception);
        }
        $this->ensuredExistingStreams[$this->getContextName()][$stream->streamName()->toString()] = true;
    }

    public function appendTo(StreamName $streamName, Iterator $streamEvents): void
    {
        if (! isset($this->ensuredExistingStreams[$this->getContextName()][$streamName->toString()]) && ! $this->hasStream($streamName)) {
            $this->create(new Stream($streamName, $streamEvents, []));
        } else {
            try {
                $this->getEventStore($streamName)->appendTo($streamName, $streamEvents);
            } catch (StreamNotFound) {
                $this->create(new Stream($streamName, $streamEvents, []));
            } catch (ProophConcurrencyException $exception) {
                throw new ConcurrencyException($exception->getMessage(), $exception->getCode(), $exception);
            }
        }
    }

    public function delete(StreamName $streamName): void
    {
        $this->getEventStore()->delete($streamName);
        unset($this->ensuredExistingStreams[$streamName->toString()]);
    }

    public function prepareEventStore(): void
    {
        $connectionName = $this->getContextName();
        if (! $this->canBeInitialized || isset($this->initializated[$connectionName]) || $this->eventSourcingConfiguration->isInMemory()) {
            return;
        }

        if (! SchemaManagerCompatibility::tableExists($this->getConnection(), $this->eventSourcingConfiguration->getEventStreamTableName())) {
            match ($this->getEventStoreType()) {
                self::EVENT_STORE_TYPE_POSTGRES => $this->createPostgresEventStreamTable(),
                self::EVENT_STORE_TYPE_MARIADB => $this->createMariadbEventStreamTable(),
                self::EVENT_STORE_TYPE_MYSQL => $this->createMysqlEventStreamTable()
            };
        }
        if (! SchemaManagerCompatibility::tableExists($this->getConnection(), $this->eventSourcingConfiguration->getProjectionsTable())) {
            match ($this->getEventStoreType()) {
                self::EVENT_STORE_TYPE_POSTGRES => $this->createPostgresProjectionTable(),
                self::EVENT_STORE_TYPE_MARIADB => $this->createMariadbProjectionTable(),
                self::EVENT_STORE_TYPE_MYSQL => $this->createMysqlProjectionTable()
            };
        }

        $this->initializated[$connectionName] = true;
    }

    public function getEventStore(?StreamName $streamName = null, string|null $streamStrategy = null): EventStore
    {
        $contextName = $this->getContextName($streamName);
        if (isset($this->initializedEventStore[$contextName])) {
            return $this->initializedEventStore[$contextName];
        }
        $this->prepareEventStore();

        if ($this->eventSourcingConfiguration->isInMemory()) {
            $this->initializedEventStore[$contextName] = $this->eventSourcingConfiguration->getInMemoryEventStore();

            return $this->initializedEventStore[$contextName];
        }

        $eventStoreType =  $this->getEventStoreType();

        $persistenceStrategy = match ($eventStoreType) {
            self::EVENT_STORE_TYPE_MYSQL => $this->getMysqlPersistenceStrategyFor($streamName, $streamStrategy),
            self::EVENT_STORE_TYPE_MARIADB => $this->getMariaDbPersistenceStrategyFor($streamName, $streamStrategy),
            self::EVENT_STORE_TYPE_POSTGRES => $this->getPostgresPersistenceStrategyFor($streamName, $streamStrategy),
            default => throw InvalidArgumentException::create('Unexpected match value ' . $eventStoreType)
        };

        $writeLockStrategy = new NoLockStrategy();
        $connection = $this->getWrappedConnection();
        if ($this->eventSourcingConfiguration->isWriteLockStrategyEnabled()) {
            $writeLockStrategy = match ($eventStoreType) {
                self::EVENT_STORE_TYPE_MYSQL => new MysqlMetadataLockStrategy($connection),
                self::EVENT_STORE_TYPE_MARIADB => new MariaDbMetadataLockStrategy($connection),
                self::EVENT_STORE_TYPE_POSTGRES => new PostgresAdvisoryLockStrategy($connection)
            };
        }

        $eventStoreClass = match ($eventStoreType) {
            self::EVENT_STORE_TYPE_MYSQL => MySqlEventStore::class,
            self::EVENT_STORE_TYPE_MARIADB => MariaDbEventStore::class,
            self::EVENT_STORE_TYPE_POSTGRES => PostgresEventStore::class
        };

        $eventStore = new $eventStoreClass(
            $this->messageFactory,
            $connection,
            $persistenceStrategy,
            $this->eventSourcingConfiguration->getLoadBatchSize(),
            $this->eventSourcingConfiguration->getEventStreamTableName(),
            true,
            $writeLockStrategy
        );

        $this->initializedEventStore[$contextName] = $eventStore;

        return $eventStore;
    }

    private function getMysqlPersistenceStrategyFor(?StreamName $streamName = null, ?string $forcedStrategy = null): PersistenceStrategy
    {
        $persistenceStrategy = $forcedStrategy ?? $this->eventSourcingConfiguration->getPersistenceStrategyFor($streamName);

        return match ($persistenceStrategy) {
            self::AGGREGATE_STREAM_PERSISTENCE => new PersistenceStrategy\MySqlAggregateStreamStrategy($this->messageConverter),
            self::PARTITION_STREAM_PERSISTENCE, self::SINGLE_STREAM_PERSISTENCE => new PersistenceStrategy\MySqlSingleStreamStrategy($this->messageConverter),
            self::SIMPLE_STREAM_PERSISTENCE => new InterlopMysqlSimpleStreamStrategy($this->messageConverter),
            self::CUSTOM_STREAM_PERSISTENCE => $this->eventSourcingConfiguration->getCustomPersistenceStrategy(),
        };
    }

    private function getMariaDbPersistenceStrategyFor(?StreamName $streamName = null, ?string $forcedStrategy = null): PersistenceStrategy
    {
        $persistenceStrategy = $forcedStrategy ?? $this->eventSourcingConfiguration->getPersistenceStrategyFor($streamName);

        return match ($persistenceStrategy) {
            self::AGGREGATE_STREAM_PERSISTENCE => new PersistenceStrategy\MariaDbAggregateStreamStrategy($this->messageConverter),
            self::PARTITION_STREAM_PERSISTENCE, self::SINGLE_STREAM_PERSISTENCE => new PersistenceStrategy\MariaDbSingleStreamStrategy($this->messageConverter),
            self::SIMPLE_STREAM_PERSISTENCE => new InterlopMariaDbSimpleStreamStrategy($this->messageConverter),
            self::CUSTOM_STREAM_PERSISTENCE => $this->eventSourcingConfiguration->getCustomPersistenceStrategy(),
        };
    }

    private function getPostgresPersistenceStrategyFor(?StreamName $streamName = null, ?string $forcedStrategy = null): PersistenceStrategy
    {
        $persistenceStrategy = $forcedStrategy ?? $this->eventSourcingConfiguration->getPersistenceStrategyFor($streamName);

        return match ($persistenceStrategy) {
            self::AGGREGATE_STREAM_PERSISTENCE => new PersistenceStrategy\PostgresAggregateStreamStrategy($this->messageConverter),
            self::PARTITION_STREAM_PERSISTENCE, self::SINGLE_STREAM_PERSISTENCE => new PersistenceStrategy\PostgresSingleStreamStrategy($this->messageConverter),
            self::SIMPLE_STREAM_PERSISTENCE => new PersistenceStrategy\PostgresSimpleStreamStrategy($this->messageConverter),
            self::CUSTOM_STREAM_PERSISTENCE => $this->eventSourcingConfiguration->getCustomPersistenceStrategy(),
        };
    }

    public function getEventStoreType(): string
    {
        if ($this->eventSourcingConfiguration->isInMemory()) {
            return self::EVENT_STORE_TYPE_IN_MEMORY;
        }

        $connection = $this->getWrappedConnection();

        $eventStoreType = $connection->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($eventStoreType === self::EVENT_STORE_TYPE_MYSQL && str_contains($connection->getAttribute(PDO::ATTR_SERVER_VERSION), 'MariaDB')) {
            $eventStoreType = self::EVENT_STORE_TYPE_MARIADB;
        }
        if ($eventStoreType === 'pgsql') {
            $eventStoreType = self::EVENT_STORE_TYPE_POSTGRES;
        }
        return $eventStoreType;
    }

    private function getConnection(): \Doctrine\DBAL\Connection
    {
        $connectionFactory = new DbalReconnectableConnectionFactory($this->connectionFactory);

        return $connectionFactory->getConnection();
    }

    /**
     * @return PDOConnection|PDO
     */
    public function getWrappedConnection()
    {
        try {
            return $this->getConnection()->getNativeConnection();
        } catch (Throwable) {
            return $this->getConnectionInLegacyOrLaravelWay();
        }
    }

    private function createMysqlEventStreamTable(): void
    {
        $this->getConnection()->executeStatement(<<<SQL
                CREATE TABLE `event_streams` (
              `no` BIGINT(20) NOT NULL AUTO_INCREMENT,
              `real_stream_name` VARCHAR(150) NOT NULL,
              `stream_name` CHAR(41) NOT NULL,
              `metadata` JSON,
              `category` VARCHAR(150),
              PRIMARY KEY (`no`),
              UNIQUE KEY `ix_rsn` (`real_stream_name`),
              KEY `ix_cat` (`category`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin;
            SQL);
    }

    private function createMariadbEventStreamTable(): void
    {
        $this->getConnection()->executeStatement(<<<SQL
            CREATE TABLE `event_streams` (
                `no` BIGINT(20) NOT NULL AUTO_INCREMENT,
                `real_stream_name` VARCHAR(150) NOT NULL,
                `stream_name` CHAR(41) NOT NULL,
                `metadata` LONGTEXT NOT NULL,
                `category` VARCHAR(150),
                CHECK (`metadata` IS NOT NULL OR JSON_VALID(`metadata`)),
                PRIMARY KEY (`no`),
                UNIQUE KEY `ix_rsn` (`real_stream_name`),
                KEY `ix_cat` (`category`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin;
            SQL);
    }

    private function createPostgresEventStreamTable(): void
    {
        $this->getConnection()->executeStatement(<<<SQL
            CREATE TABLE event_streams (
              no BIGSERIAL,
              real_stream_name VARCHAR(150) NOT NULL,
              stream_name CHAR(41) NOT NULL,
              metadata JSONB,
              category VARCHAR(150),
              PRIMARY KEY (no),
              UNIQUE (stream_name)
            );
            CREATE INDEX on event_streams (category);
            SQL);
    }

    private function createMysqlProjectionTable(): void
    {
        $this->getConnection()->executeStatement(
            <<<SQL
                CREATE TABLE `projections` (
                  `no` BIGINT(20) NOT NULL AUTO_INCREMENT,
                  `name` VARCHAR(150) NOT NULL,
                  `position` JSON,
                  `state` JSON,
                  `status` VARCHAR(28) NOT NULL,
                  `locked_until` CHAR(26),
                  PRIMARY KEY (`no`),
                  UNIQUE KEY `ix_name` (`name`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin;
                SQL
        );
    }

    private function createMariadbProjectionTable(): void
    {
        $this->getConnection()->executeStatement(
            <<<SQL
                CREATE TABLE `projections` (
                  `no` BIGINT(20) NOT NULL AUTO_INCREMENT,
                  `name` VARCHAR(150) NOT NULL,
                  `position` LONGTEXT,
                  `state` LONGTEXT,
                  `status` VARCHAR(28) NOT NULL,
                  `locked_until` CHAR(26),
                  CHECK (`position` IS NULL OR JSON_VALID(`position`)),
                  CHECK (`state` IS NULL OR JSON_VALID(`state`)),
                  PRIMARY KEY (`no`),
                  UNIQUE KEY `ix_name` (`name`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin;
                SQL
        );
    }

    private function createPostgresProjectionTable(): void
    {
        $this->getConnection()->executeStatement(
            <<<SQL
                CREATE TABLE projections (
                  no BIGSERIAL,
                  name VARCHAR(150) NOT NULL,
                  position JSONB,
                  state JSONB,
                  status VARCHAR(28) NOT NULL,
                  locked_until CHAR(26),
                  PRIMARY KEY (no),
                  UNIQUE (name)
                );
                SQL
        );
    }

    /**
     * @param \Doctrine\DBAL\Driver\Connection|null $connection
     * @return bool
     */
    private function isDbalVersionThreeOrHigher(?\Doctrine\DBAL\Driver\Connection $connection): bool
    {
        return $connection instanceof \Doctrine\DBAL\Driver\PDO\Connection;
    }

    private function getConnectionInLegacyOrLaravelWay()
    {
        /** Case when getNativeConnection is not implemented in nested connection */
        $connection = $this->getConnection()->getWrappedConnection();

        if ($connection instanceof PDO || is_subclass_of($connection, "Doctrine\DBAL\Driver\PDOConnection") || get_class($connection) === "Doctrine\DBAL\Driver\PDOConnection") {
            return $connection;
        }

        /** Laravel case @look Illuminate\Database\PDO\Connection */
        return $connection->getWrappedConnection();
    }

    public function getContextName(?StreamName $streamName = null): string
    {
        $connectionName = 'default';
        if ($this->connectionFactory instanceof MultiTenantConnectionFactory) {
            $connectionName = $this->connectionFactory->currentActiveTenant();
        }

        if ($streamName !== null) {
            $connectionName .= '-' . $streamName->toString();
        }

        return $connectionName;
    }
}
