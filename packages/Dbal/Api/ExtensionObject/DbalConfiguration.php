<?php

namespace Ecotone\Api\Dbal\ExtensionObject;

use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Gateway\DocumentStore;
use Ecotone\Dbal\Deduplication\DeduplicationModule;
use Ecotone\Messaging\Config\ConfigurationException;

/**
 * licence Apache-2.0
 */
final class DbalConfiguration
{
    public const DEFAULT_TRANSACTION_ON_ASYNCHRONOUS_ENDPOINTS = true;
    public const DEFAULT_TRANSACTION_ON_COMMAND_BUS = true;
    public const DEFAULT_TRANSACTION_ON_CONSOLE_COMMANDS = true;
    public const DEFAULT_CLEAR_AND_FLUSH_OBJECT_MANAGER = true;
    public const DEFAULT_DEDUPLICATION_ENABLED = true;
    public const DEFAULT_DEAD_LETTER_ENABLED = true;

    private function __construct(
        public readonly bool $transactionOnAsynchronousEndpoints = self::DEFAULT_TRANSACTION_ON_ASYNCHRONOUS_ENDPOINTS,
        public readonly array $disableTransactionsOnAsynchronousEndpointNames = [],
        public readonly bool $transactionOnCommandBus = self::DEFAULT_TRANSACTION_ON_COMMAND_BUS,
        public readonly bool $transactionOnConsoleCommands = self::DEFAULT_TRANSACTION_ON_CONSOLE_COMMANDS,
        public readonly bool $clearObjectManagerOnAsynchronousEndpoints = self::DEFAULT_CLEAR_AND_FLUSH_OBJECT_MANAGER,
        public readonly bool $clearAndFlushObjectManagerOnCommandBus = self::DEFAULT_CLEAR_AND_FLUSH_OBJECT_MANAGER,
        public readonly bool $clearAndFlushObjectManagerOnProjectionBatch = self::DEFAULT_CLEAR_AND_FLUSH_OBJECT_MANAGER,
        public readonly bool $flushDuringPersisting = true,
        public readonly array $defaultConnectionReferenceNames = [DbalConnectionReference::DEFAULT],
        public readonly bool $deduplicatedEnabled = self::DEFAULT_DEDUPLICATION_ENABLED,
        public readonly bool $deduplicationInMemory = false,
        public readonly bool $deadLetterEnabled = self::DEFAULT_DEAD_LETTER_ENABLED,
        public readonly bool $deadLetterInMemory = false,
        private readonly ?string $deduplicationConnectionReference = null,
        private readonly ?string $deadLetterConnectionReference = null,
        public readonly bool $consumerPositionTrackingEnabled = true,
        public readonly string $consumerPositionTrackingConnectionReference = DbalConnectionReference::DEFAULT,
        public readonly bool $enableDoctrineORMRepositories = false,
        public readonly ?string $doctrineORMRepositoryConnectionReference = null,
        public readonly ?array $doctrineORMClasses = null,
        public readonly bool $enableDbalDocumentStore = true,
        public readonly string $dbalDocumentStoreReference = DocumentStore::class,
        public readonly string $documentStoreConnectionReference = DbalConnectionReference::DEFAULT,
        public readonly bool $inMemoryDocumentStore = false,
        public readonly ?array $documentStoreRelatedAggregates = null,
        public readonly bool $enableDocumentStoreStateStoredRepository = false,
        public readonly int $minimumTimeToRemoveMessageInMilliseconds = DeduplicationModule::REMOVE_MESSAGE_AFTER_7_DAYS,
        public readonly int $deduplicationRemovalBatchSize = 1000,
    ) {
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
            ->withClearAndFlushObjectManagerOnProjectionBatch(false)
            ->withDocumentStore(true, true);
    }

    /**
     * @param object[] $extensionObjects
     */
    public static function createDefaultFor(array $extensionObjects): self
    {
        foreach ($extensionObjects as $extensionObject) {
            if ($extensionObject instanceof TestConfiguration) {
                return new self(deduplicationInMemory: true, deadLetterInMemory: true);
            }
        }

        return self::createWithDefaults();
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
            return DbalConnectionReference::DEFAULT;
        }

        if (count($this->defaultConnectionReferenceNames) !== 1) {
            throw ConfigurationException::create("Specify exact connection for {$type}. Got: " . implode(',', $this->defaultConnectionReferenceNames));
        }

        return $this->defaultConnectionReferenceNames[0];
    }

    public function withTransactionOnAsynchronousEndpoints(bool $isTransactionEnabled): self
    {
        return new self(...[...get_object_vars($this), 'transactionOnAsynchronousEndpoints' => $isTransactionEnabled]);
    }

    public function withoutTransactionOnAsynchronousEndpoints(array $names): self
    {
        return new self(...[...get_object_vars($this), 'disableTransactionsOnAsynchronousEndpointNames' => $names]);
    }

    public function withTransactionOnCommandBus(bool $isTransactionEnabled): self
    {
        return new self(...[...get_object_vars($this), 'transactionOnCommandBus' => $isTransactionEnabled]);
    }

    public function withDoctrineORMRepositories(bool $isORMEnabled, ?array $relatedClasses = null, string $connectionReferenceName = DbalConnectionReference::DEFAULT): self
    {
        return new self(...[...get_object_vars($this), 'enableDoctrineORMRepositories' => $isORMEnabled, 'doctrineORMRepositoryConnectionReference' => $connectionReferenceName, 'doctrineORMClasses' => $relatedClasses]);
    }

    public function withTransactionOnConsoleCommands(bool $isTransactionEnabled): self
    {
        return new self(...[...get_object_vars($this), 'transactionOnConsoleCommands' => $isTransactionEnabled]);
    }

    public function withClearAndFlushObjectManagerOnAsynchronousEndpoints(bool $isEnabled): self
    {
        return new self(...[...get_object_vars($this), 'clearObjectManagerOnAsynchronousEndpoints' => $isEnabled]);
    }

    public function withClearAndFlushObjectManagerOnCommandBus(bool $isEnabled): self
    {
        return new self(...[...get_object_vars($this), 'clearAndFlushObjectManagerOnCommandBus' => $isEnabled]);
    }

    public function withClearAndFlushObjectManagerOnProjectionBatch(bool $isEnabled): self
    {
        return new self(...[...get_object_vars($this), 'clearAndFlushObjectManagerOnProjectionBatch' => $isEnabled]);
    }

    public function withFlushWhenPersisting(bool $isEnabled): self
    {
        return new self(...[...get_object_vars($this), 'flushDuringPersisting' => $isEnabled]);
    }

    public function withDefaultConnectionReferenceNames(array $connectionReferenceNames = [DbalConnectionReference::DEFAULT]): self
    {
        return new self(...[...get_object_vars($this), 'defaultConnectionReferenceNames' => $connectionReferenceNames]);
    }

    /**
     * @link https://docs.ecotone.tech/modelling/recovering-tracing-and-monitoring/resiliency/idempotent-consumer-deduplication#deduplication-clean-up
     */
    public function withDeduplication(bool $isDeduplicatedEnabled = true, string $connectionReference = DbalConnectionReference::DEFAULT, int $expirationTime = DeduplicationModule::REMOVE_MESSAGE_AFTER_7_DAYS, int $removalBatchSize = 1000, bool $inMemory = false): self
    {
        return new self(...[...get_object_vars($this), 'deduplicatedEnabled' => $isDeduplicatedEnabled, 'deduplicationInMemory' => $inMemory, 'deduplicationConnectionReference' => $connectionReference, 'minimumTimeToRemoveMessageInMilliseconds' => $expirationTime, 'deduplicationRemovalBatchSize' => $removalBatchSize]);
    }

    public function withDeadLetter(bool $isDeadLetterEnabled, string $connectionReference = DbalConnectionReference::DEFAULT, bool $inMemory = false): self
    {
        return new self(...[...get_object_vars($this), 'deadLetterEnabled' => $isDeadLetterEnabled, 'deadLetterInMemory' => $inMemory, 'deadLetterConnectionReference' => $connectionReference]);
    }

    public function withConsumerPositionTracking(
        bool $enabled = true,
        string $connectionReference = DbalConnectionReference::DEFAULT
    ): self {
        return new self(...[...get_object_vars($this), 'consumerPositionTrackingEnabled' => $enabled, 'consumerPositionTrackingConnectionReference' => $connectionReference]);
    }

    public function withDocumentStore(bool $isDocumentStoreEnabled = true, bool $inMemoryDocumentStore = false, string $reference = DocumentStore::class, bool $enableDocumentStoreStateStoredRepository = false, string $connectionReference = DbalConnectionReference::DEFAULT, ?array $documentStoreRelatedAggregates = null): self
    {
        return new self(...[...get_object_vars($this), 'enableDbalDocumentStore' => $isDocumentStoreEnabled, 'inMemoryDocumentStore' => $inMemoryDocumentStore, 'dbalDocumentStoreReference' => $reference, 'documentStoreConnectionReference' => $connectionReference, 'enableDocumentStoreStateStoredRepository' => $enableDocumentStoreStateStoredRepository, 'documentStoreRelatedAggregates' => $documentStoreRelatedAggregates]);
    }
}
