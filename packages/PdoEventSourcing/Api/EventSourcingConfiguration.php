<?php

declare(strict_types=1);

namespace Ecotone\Api\EventSourcing;

use Ecotone\Api\Dbal\ExtensionObject\DbalConnectionReference;
use Ecotone\Modelling\BaseEventSourcingConfiguration;

/**
 * licence Apache-2.0
 */
final class EventSourcingConfiguration extends BaseEventSourcingConfiguration
{
    public const LOAD_BATCH_SIZE = 1000;
    public const DEFAULT_EVENT_STREAM_TABLE_NAME = 'ecotone_event_stream';

    private int $loadBatchSize = self::LOAD_BATCH_SIZE;
    private string $defaultEventStreamTableName = self::DEFAULT_EVENT_STREAM_TABLE_NAME;
    public readonly string $eventStoreReferenceName;
    private string $connectionReferenceName;
    private bool $isInMemory = false;

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

    public static function withDefaults(): static
    {
        return new self();
    }

    public static function createInMemory(): static
    {
        $eventSourcingConfiguration = new self();
        $eventSourcingConfiguration->isInMemory = true;

        return $eventSourcingConfiguration;
    }

    public function withLoadBatchSize(int $loadBatchSize): static
    {
        $this->loadBatchSize = $loadBatchSize;

        return $this;
    }

    public function withDefaultEventStreamTableName(string $defaultEventStreamTableName): static
    {
        $this->defaultEventStreamTableName = $defaultEventStreamTableName;

        return $this;
    }

    public function getLoadBatchSize(): int
    {
        return $this->loadBatchSize;
    }

    public function getDefaultEventStreamTableName(): string
    {
        return $this->defaultEventStreamTableName;
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
