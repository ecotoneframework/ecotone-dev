<?php

namespace Ecotone\EventSourcing;

use Ecotone\EventSourcing\EventStore\InMemoryEventStore as EcotoneInMemoryEventStore;
use Ecotone\EventSourcing\InMemory\CachingInMemoryProjectionManager;
use Ecotone\EventSourcing\InMemory\InMemoryProjectionManager;
use Ecotone\EventSourcing\Prooph\LazyProophEventStore;
use Ecotone\EventSourcing\Prooph\ProophInMemoryEventStoreAdapter;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Modelling\BaseEventSourcingConfiguration;
use Enqueue\Dbal\DbalConnectionFactory;
use Prooph\EventStore\Pdo\PersistenceStrategy;
use Prooph\EventStore\StreamName;

/**
 * licence Apache-2.0
 *
 * @TODO Ecotone 2.0 Leave only meaningful configuration for ProjectionV2
 */
class EventSourcingConfiguration extends BaseEventSourcingConfiguration
{
    private bool $initializeEventStoreOnStart = LazyProophEventStore::INITIALIZE_ON_STARTUP;
    private int $loadBatchSize = LazyProophEventStore::LOAD_BATCH_SIZE;
    private bool $enableWriteLockStrategy = LazyProophEventStore::DEFAULT_ENABLE_WRITE_LOCK_STRATEGY;
    private string $eventStreamTableName = LazyProophEventStore::DEFAULT_STREAM_TABLE;
    private string $projectionsTable = LazyProophEventStore::DEFAULT_PROJECTIONS_TABLE;
    private string $eventStoreReferenceName;
    private string $projectManagerReferenceName;
    private string $connectionReferenceName;
    private string $persistenceStrategy = LazyProophEventStore::PARTITION_STREAM_PERSISTENCE;
    private ?PersistenceStrategy $customPersistenceStrategyInstance = null;
    private bool $isInMemory = false;
    private ?EcotoneInMemoryEventStore $inMemoryEventStore = null;
    private ?\Prooph\EventStore\Projection\ProjectionManager $inMemoryProjectionManager = null;
    private ?ProophInMemoryEventStoreAdapter $inMemoryEventStoreAdapter = null;
    /** @var array<string> */
    private array $persistenceStrategies = [];

    private function __construct(string $connectionReferenceName = DbalConnectionFactory::class, string $eventStoreReferenceName = EventStore::class, string $projectManagerReferenceName = ProjectionManager::class)
    {
        $this->eventStoreReferenceName = $eventStoreReferenceName;
        $this->projectManagerReferenceName = $projectManagerReferenceName;
        $this->connectionReferenceName = $connectionReferenceName;

        parent::__construct();
    }

    public static function create(string $connectionReferenceName = DbalConnectionFactory::class, string $eventStoreReferenceName = EventStore::class, string $projectManagerReferenceName = ProjectionManager::class): static
    {
        return new self($connectionReferenceName, $eventStoreReferenceName, $projectManagerReferenceName);
    }

    public static function createWithDefaults(): static
    {
        return new self();
    }

    public static function createInMemory(): static
    {
        $eventSourcingConfiguration = new self();
        $eventSourcingConfiguration->isInMemory = true;
        $eventSourcingConfiguration->inMemoryEventStore = new EcotoneInMemoryEventStore();
        $eventSourcingConfiguration->inMemoryEventStoreAdapter = new ProophInMemoryEventStoreAdapter($eventSourcingConfiguration->inMemoryEventStore);
        $eventSourcingConfiguration->inMemoryProjectionManager = new CachingInMemoryProjectionManager(new InMemoryProjectionManager($eventSourcingConfiguration->inMemoryEventStoreAdapter));

        return $eventSourcingConfiguration;
    }

    /**
     * This work as simple stream strategy, however put constraints on aggregate_id, aggregate_version, aggregate_type being present
     * @deprecated Ecotone 2.0 will be removed in favour of partition stream persistence strategy
     */
    public function withSingleStreamPersistenceStrategy(): static
    {
        $this->persistenceStrategy = LazyProophEventStore::SINGLE_STREAM_PERSISTENCE;

        return $this;
    }

    public function withPartitionStreamPersistenceStrategy(): static
    {
        $this->persistenceStrategy = LazyProophEventStore::SINGLE_STREAM_PERSISTENCE;

        return $this;
    }

    /**
     * Aggregate_id becomes a stream and each stream is separate table.
     * Be careful this create a lot of database tables.
     */
    public function withStreamPerAggregatePersistenceStrategy(): static
    {
        $this->persistenceStrategy = LazyProophEventStore::AGGREGATE_STREAM_PERSISTENCE;

        return $this;
    }

    /**
     * This does not verify, if aggregate_id, aggregate_version, aggregate_type is defined in metadata
     */
    public function withSimpleStreamPersistenceStrategy(): static
    {
        $this->persistenceStrategy = LazyProophEventStore::SIMPLE_STREAM_PERSISTENCE;

        return $this;
    }

    public function withPersistenceStrategyFor(string $streamName, string $strategy): self
    {
        $allowedStrategies = [LazyProophEventStore::SIMPLE_STREAM_PERSISTENCE, LazyProophEventStore::SINGLE_STREAM_PERSISTENCE, LazyProophEventStore::AGGREGATE_STREAM_PERSISTENCE, LazyProophEventStore::PARTITION_STREAM_PERSISTENCE];

        Assert::oneOf(
            $strategy,
            $allowedStrategies,
            sprintf('Custom persistence strategy for specific stream can only be one of %s', implode(', ', $allowedStrategies))
        );

        $this->persistenceStrategies[$streamName] = $strategy;

        return $this;
    }

    public function withCustomPersistenceStrategy(PersistenceStrategy $persistenceStrategy): static
    {
        $this->persistenceStrategy = LazyProophEventStore::CUSTOM_STREAM_PERSISTENCE;
        $this->customPersistenceStrategyInstance = $persistenceStrategy;

        return $this;
    }

    public function getInMemoryEventStoreAdapter(): ?ProophInMemoryEventStoreAdapter
    {
        return $this->inMemoryEventStoreAdapter;
    }

    public function getInMemoryEventStore(): ?EcotoneInMemoryEventStore
    {
        return $this->inMemoryEventStore;
    }

    public function getInMemoryProjectionManager(): ?\Prooph\EventStore\Projection\ProjectionManager
    {
        return $this->inMemoryProjectionManager;
    }

    public function withInitializeEventStoreOnStart(bool $isInitializedOnStartup): static
    {
        $this->initializeEventStoreOnStart = $isInitializedOnStartup;

        return $this;
    }

    public function withLoadBatchSize(int $loadBatchSize): static
    {
        $this->loadBatchSize = $loadBatchSize;

        return $this;
    }

    public function withWriteLockStrategy(bool $enableWriteLockStrategy): static
    {
        $this->enableWriteLockStrategy = $enableWriteLockStrategy;

        return $this;
    }

    public function withEventStreamTableName(string $eventStreamTableName): static
    {
        $this->eventStreamTableName = $eventStreamTableName;

        return $this;
    }

    public function withProjectionsTableName(string $projectionsTableName): static
    {
        $this->projectionsTable = $projectionsTableName;

        return $this;
    }

    public function isInitializedOnStart(): bool
    {
        return $this->initializeEventStoreOnStart;
    }

    public function isUsingAggregateStreamStrategyFor(string $streamName): bool
    {
        return
            $this->getPersistenceStrategy() === LazyProophEventStore::AGGREGATE_STREAM_PERSISTENCE
            || (array_key_exists($streamName, $this->persistenceStrategies) && $this->persistenceStrategies[$streamName] === LazyProophEventStore::AGGREGATE_STREAM_PERSISTENCE)
        ;
    }

    public function isUsingSimpleStreamStrategy(): bool
    {
        return $this->getPersistenceStrategy() === LazyProophEventStore::SIMPLE_STREAM_PERSISTENCE;
    }

    public function getPersistenceStrategy(): string
    {
        return $this->persistenceStrategy;
    }

    public function getPersistenceStrategyFor(?StreamName $streamName = null): string
    {
        return $this->persistenceStrategies[$streamName?->toString()] ?? $this->persistenceStrategy;
    }

    public function getCustomPersistenceStrategy(): PersistenceStrategy
    {
        return $this->customPersistenceStrategyInstance;
    }

    public function getLoadBatchSize(): int
    {
        return $this->loadBatchSize;
    }

    public function isWriteLockStrategyEnabled(): bool
    {
        return $this->enableWriteLockStrategy;
    }

    public function getEventStreamTableName(): string
    {
        return $this->eventStreamTableName;
    }

    public function getProjectionsTable(): string
    {
        return $this->projectionsTable;
    }

    public function getEventStoreReferenceName(): string
    {
        return $this->eventStoreReferenceName;
    }

    public function getProjectManagerReferenceName(): string
    {
        return $this->projectManagerReferenceName;
    }

    public function getConnectionReferenceName(): string
    {
        return $this->connectionReferenceName;
    }

    public function isInMemory(): bool
    {
        return $this->isInMemory;
    }
}
