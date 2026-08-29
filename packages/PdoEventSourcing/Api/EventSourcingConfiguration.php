<?php

namespace Ecotone\Api\EventSourcing;

use Ecotone\Api\Dbal\DbalConnectionReference;
use Ecotone\EventSourcing\EventStore;
use Ecotone\EventSourcing\EventStore\InMemoryEventStore as EcotoneInMemoryEventStore;
use Ecotone\EventSourcing\StreamTableRegistry;
use Ecotone\Modelling\BaseEventSourcingConfiguration;

/**
 * licence Apache-2.0
 */
class EventSourcingConfiguration extends BaseEventSourcingConfiguration
{
    public const INITIALIZE_ON_STARTUP = true;
    public const LOAD_BATCH_SIZE = 1000;
    public const DEFAULT_ENABLE_WRITE_LOCK_STRATEGY = false;

    private bool $initializeEventStoreOnStart = self::INITIALIZE_ON_STARTUP;
    private int $loadBatchSize = self::LOAD_BATCH_SIZE;
    private bool $enableWriteLockStrategy = self::DEFAULT_ENABLE_WRITE_LOCK_STRATEGY;
    private string $eventStreamTableName = StreamTableRegistry::DEFAULT_STREAM;
    private string $eventStoreReferenceName;
    private string $connectionReferenceName;
    private bool $isInMemory = false;
    private ?EcotoneInMemoryEventStore $inMemoryEventStore = null;

    private function __construct(string $connectionReferenceName = DbalConnectionReference::DEFAULT, string $eventStoreReferenceName = EventStore::class)
    {
        $this->eventStoreReferenceName = $eventStoreReferenceName;
        $this->connectionReferenceName = $connectionReferenceName;

        parent::__construct();
    }

    public static function create(string $connectionReferenceName = DbalConnectionReference::DEFAULT, string $eventStoreReferenceName = EventStore::class): static
    {
        return new self($connectionReferenceName, $eventStoreReferenceName);
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

        return $eventSourcingConfiguration;
    }

    public function getInMemoryEventStore(): ?EcotoneInMemoryEventStore
    {
        return $this->inMemoryEventStore;
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

    public function isInitializedOnStart(): bool
    {
        return $this->initializeEventStoreOnStart;
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

    public function getEventStoreReferenceName(): string
    {
        return $this->eventStoreReferenceName;
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
