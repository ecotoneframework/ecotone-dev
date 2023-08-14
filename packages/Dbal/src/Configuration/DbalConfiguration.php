<?php

namespace Ecotone\Dbal\Configuration;

use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Store\Document\DocumentStore;
use Enqueue\Dbal\DbalConnectionFactory;

class DbalConfiguration
{
    public const DEFAULT_TRANSACTION_ON_ASYNCHRONOUS_ENDPOINTS = true;
    public const DEFAULT_TRANSACTION_ON_COMMAND_BUS = true;
    public const DEFAULT_TRANSACTION_ON_CONSOLE_COMMANDS = true;
    public const DEFAULT_CLEAR_AND_FLUSH_OBJECT_MANAGER = true;
    public const DEFAULT_DEDUPLICATION_ENABLED = true;
    public const DEFAULT_DEAD_LETTER_ENABLED = true;

    private bool $transactionOnAsynchronousEndpoints = self::DEFAULT_TRANSACTION_ON_ASYNCHRONOUS_ENDPOINTS;
    private array $disableTransactionsOnAsynchronousEndpointNames = [];
    private bool $transactionOnCommandBus = self::DEFAULT_TRANSACTION_ON_COMMAND_BUS;
    private bool $transactionOnConsoleCommands = self::DEFAULT_TRANSACTION_ON_CONSOLE_COMMANDS;
    private bool $clearObjectManagerOnAsynchronousEndpoints = self::DEFAULT_CLEAR_AND_FLUSH_OBJECT_MANAGER;
    private bool $clearAndFlushObjectManagerOnCommandBus = self::DEFAULT_CLEAR_AND_FLUSH_OBJECT_MANAGER;
    private array $defaultConnectionReferenceNames = [DbalConnectionFactory::class];

    private bool $deduplicatedEnabled = self::DEFAULT_DEDUPLICATION_ENABLED;
    private bool $deadLetterEnabled = self::DEFAULT_DEAD_LETTER_ENABLED;

    private ?string $deduplicationConnectionReference = null;
    private ?string $deadLetterConnectionReference = null;

    private bool $enableDoctrineORMRepositories = false;
    private ?string $doctrineORMRepositoryConnectionReference = null;
    private ?array $doctrineORMClasses = null;
    private bool $enableDbalDocumentStore = true;
    private string $dbalDocumentStoreReference = DocumentStore::class;
    private bool $initializeDbalDocumentStore = true;
    private string $documentStoreConnectionReference = DbalConnectionFactory::class;
    private bool $inMemoryDocumentStore = false;

    private bool $enableDocumentStoreAggregateRepository = false;

    private function __construct()
    {
    }

    public static function createWithDefaults(): self
    {
        return new self();
    }

    public static function createForTesting(): self
    {
        return (new self())
            ->withTransactionOnAsynchronousEndpoints(false)
            ->withTransactionOnCommandBus(false)
            ->withTransactionOnConsoleCommands(false)
            ->withDeduplication(false)
            ->withDeadLetter(false)
            ->withClearAndFlushObjectManagerOnAsynchronousEndpoints(false)
            ->withClearAndFlushObjectManagerOnCommandBus(false)
            ->withDocumentStore(true, true);
    }

    public function getDeduplicationConnectionReference(): string
    {
        return $this->getMainConnectionOrDefault($this->deduplicationConnectionReference, 'deduplication');
    }

    public function getDeadLetterConnectionReference(): string
    {
        return $this->getMainConnectionOrDefault($this->deadLetterConnectionReference, 'dead letter');
    }

    private function getMainConnectionOrDefault(?string $connectionReferenceName, string $type): string
    {
        if ($connectionReferenceName) {
            return $connectionReferenceName;
        }

        if (empty($this->defaultConnectionReferenceNames)) {
            return DbalConnectionFactory::class;
        }

        if (count($this->defaultConnectionReferenceNames) !== 1) {
            throw ConfigurationException::create("Specify exact connection for {$type}. Got: " . implode(',', $this->defaultConnectionReferenceNames));
        }

        return $this->defaultConnectionReferenceNames[0];
    }

    public function isDoctrineORMRepositoriesEnabled(): bool
    {
        return $this->enableDoctrineORMRepositories;
    }

    public function getDoctrineORMRepositoryConnectionReference(): ?string
    {
        return $this->doctrineORMRepositoryConnectionReference;
    }

    public function getDoctrineORMClasses(): ?array
    {
        return $this->doctrineORMClasses;
    }

    public function withTransactionOnAsynchronousEndpoints(bool $isTransactionEnabled): self
    {
        $self                                     = clone $this;
        $self->transactionOnAsynchronousEndpoints = $isTransactionEnabled;

        return $self;
    }

    public function withoutTransactionOnAsynchronousEndpoints(array $names): self
    {
        $self                                     = clone $this;
        $self->disableTransactionsOnAsynchronousEndpointNames = $names;

        return $self;
    }

    public function withTransactionOnCommandBus(bool $isTransactionEnabled): self
    {
        $self                          = clone $this;
        $self->transactionOnCommandBus = $isTransactionEnabled;

        return $self;
    }

    public function withDoctrineORMRepositories(bool $isORMEnabled, ?array $relatedClasses = null, string $connectionReferenceName = DbalConnectionFactory::class): self
    {
        $self = clone $this;
        $self->enableDoctrineORMRepositories = $isORMEnabled;
        $self->doctrineORMRepositoryConnectionReference = $connectionReferenceName;
        $self->doctrineORMClasses = $relatedClasses;

        return $self;
    }

    public function withTransactionOnConsoleCommands(bool $isTransactionEnabled): self
    {
        $self                          = clone $this;
        $self->transactionOnConsoleCommands = $isTransactionEnabled;

        return $self;
    }

    /**
     * @TODO Ecotone 2.0 rename to withClearAndFlushObjectManagerOnAsynchronousEndpoints
     * @deprecated use withClearAndFlushObjectManagerOnAsynchronousEndpoints
     */
    public function withCleanObjectManagerOnAsynchronousEndpoints(bool $isEnabled): self
    {
        $self                                     = clone $this;
        $self->clearObjectManagerOnAsynchronousEndpoints = $isEnabled;

        return $self;
    }

    public function withClearAndFlushObjectManagerOnAsynchronousEndpoints(bool $isEnabled): self
    {
        $self                                     = clone $this;
        $self->clearObjectManagerOnAsynchronousEndpoints = $isEnabled;

        return $self;
    }

    public function withClearAndFlushObjectManagerOnCommandBus(bool $isEnabled): self
    {
        $self                                     = clone $this;
        $self->clearAndFlushObjectManagerOnCommandBus = $isEnabled;

        return $self;
    }

    public function withDefaultConnectionReferenceNames(array $connectionReferenceNames = [DbalConnectionFactory::class]): self
    {
        $self = clone $this;
        $self->defaultConnectionReferenceNames = $connectionReferenceNames;

        return $self;
    }

    public function withDeduplication(bool $isDeduplicatedEnabled, string $connectionReference = DbalConnectionFactory::class): self
    {
        $self = clone $this;
        $self->deduplicatedEnabled = $isDeduplicatedEnabled;
        $self->deduplicationConnectionReference = $connectionReference;

        return $self;
    }

    public function withDeadLetter(bool $isDeadLetterEnabled, string $connectionReference = DbalConnectionFactory::class): self
    {
        $self = clone $this;
        $self->deadLetterEnabled = $isDeadLetterEnabled;
        $self->deadLetterConnectionReference = $connectionReference;

        return $self;
    }

    public function withDocumentStore(bool $isDocumentStoreEnabled = true, bool $inMemoryDocumentStore = false, string $reference = DocumentStore::class, bool $initializeDatabaseTable = true, bool $enableDocumentStoreAggregateRepository = false, string $connectionReference = DbalConnectionFactory::class): self
    {
        $self = clone $this;
        $self->enableDbalDocumentStore = $isDocumentStoreEnabled;
        $self->inMemoryDocumentStore = $inMemoryDocumentStore;
        $self->dbalDocumentStoreReference = $reference;
        $self->initializeDbalDocumentStore = $initializeDatabaseTable;
        $self->documentStoreConnectionReference = $connectionReference;
        $self->enableDocumentStoreAggregateRepository = $enableDocumentStoreAggregateRepository;

        return $self;
    }

    public function isDeduplicatedEnabled(): bool
    {
        return $this->deduplicatedEnabled;
    }

    public function isDeadLetterEnabled(): bool
    {
        return $this->deadLetterEnabled;
    }

    public function isTransactionOnAsynchronousEndpoints(): bool
    {
        return $this->transactionOnAsynchronousEndpoints;
    }

    public function isTransactionOnCommandBus(): bool
    {
        return $this->transactionOnCommandBus;
    }

    public function isTransactionOnConsoleCommands(): bool
    {
        return $this->transactionOnConsoleCommands;
    }

    public function getDefaultConnectionReferenceNames(): array
    {
        return $this->defaultConnectionReferenceNames;
    }

    public function isClearObjectManagerOnAsynchronousEndpoints(): bool
    {
        return $this->clearObjectManagerOnAsynchronousEndpoints;
    }

    public function isClearAndFlushObjectManagerOnCommandBus(): bool
    {
        return $this->clearAndFlushObjectManagerOnCommandBus;
    }

    public function isEnableDbalDocumentStore(): bool
    {
        return $this->enableDbalDocumentStore;
    }

    public function getDbalDocumentStoreReference(): string
    {
        return $this->dbalDocumentStoreReference;
    }

    public function isInitializeDbalDocumentStore(): bool
    {
        return $this->initializeDbalDocumentStore;
    }

    public function getDocumentStoreConnectionReference(): string
    {
        return $this->documentStoreConnectionReference;
    }

    public function isInMemoryDocumentStore(): bool
    {
        return $this->inMemoryDocumentStore;
    }

    public function isEnableDocumentStoreAggregateRepository(): bool
    {
        return $this->enableDocumentStoreAggregateRepository;
    }

    public function getDisabledTransactionsOnAsynchronousEndpointNames(): array
    {
        return $this->disableTransactionsOnAsynchronousEndpointNames;
    }
}
