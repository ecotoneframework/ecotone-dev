<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Unit;

use Closure;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class DbalConfigurationCopyTest extends TestCase
{
    public function test_dbal_configuration_copy_changes_only_the_named_fields(): void
    {
        $original = DbalConfiguration::createWithDefaults()
            ->withTransactionOnAsynchronousEndpoints(false)
            ->withoutTransactionOnAsynchronousEndpoints(['orders'])
            ->withTransactionOnCommandBus(false)
            ->withDoctrineORMRepositories(true, ['Order'], 'orm_connection')
            ->withTransactionOnConsoleCommands(false)
            ->withClearAndFlushObjectManagerOnAsynchronousEndpoints(false)
            ->withClearAndFlushObjectManagerOnCommandBus(false)
            ->withClearAndFlushObjectManagerOnProjectionBatch(false)
            ->withFlushWhenPersisting(false)
            ->withDefaultConnectionReferenceNames(['main_connection'])
            ->withDeduplication(false, 'deduplication_connection', 1000, 10, true)
            ->withDeadLetter(false, 'dead_letter_connection', true)
            ->withConsumerPositionTracking(false, 'position_connection')
            ->withDocumentStore(false, true, 'document_store', true, 'document_store_connection', ['Basket']);

        self::assertEqualsCanonicalizing(
            [
                'transactionOnAsynchronousEndpoints', 'disableTransactionsOnAsynchronousEndpointNames', 'transactionOnCommandBus', 'transactionOnConsoleCommands',
                'clearObjectManagerOnAsynchronousEndpoints', 'clearAndFlushObjectManagerOnCommandBus', 'clearAndFlushObjectManagerOnProjectionBatch', 'flushDuringPersisting',
                'defaultConnectionReferenceNames', 'deduplicatedEnabled', 'deduplicationInMemory', 'deadLetterEnabled', 'deadLetterInMemory',
                'consumerPositionTrackingEnabled', 'consumerPositionTrackingConnectionReference', 'enableDoctrineORMRepositories', 'doctrineORMRepositoryConnectionReference',
                'doctrineORMClasses', 'enableDbalDocumentStore', 'dbalDocumentStoreReference', 'documentStoreConnectionReference', 'inMemoryDocumentStore',
                'documentStoreRelatedAggregates', 'enableDocumentStoreStateStoredRepository', 'minimumTimeToRemoveMessageInMilliseconds', 'deduplicationRemovalBatchSize',
            ],
            array_keys(get_object_vars($original)),
        );
        self::assertCopyChangesOnly($original, fn (DbalConfiguration $configuration) => $configuration->withTransactionOnAsynchronousEndpoints(true), ['transactionOnAsynchronousEndpoints' => true]);
        self::assertCopyChangesOnly($original, fn (DbalConfiguration $configuration) => $configuration->withoutTransactionOnAsynchronousEndpoints(['payments']), ['disableTransactionsOnAsynchronousEndpointNames' => ['payments']]);
        self::assertCopyChangesOnly($original, fn (DbalConfiguration $configuration) => $configuration->withTransactionOnCommandBus(true), ['transactionOnCommandBus' => true]);
        self::assertCopyChangesOnly(
            $original,
            fn (DbalConfiguration $configuration) => $configuration->withDoctrineORMRepositories(false, ['Invoice'], 'other_orm_connection'),
            ['enableDoctrineORMRepositories' => false, 'doctrineORMClasses' => ['Invoice'], 'doctrineORMRepositoryConnectionReference' => 'other_orm_connection'],
        );
        self::assertCopyChangesOnly($original, fn (DbalConfiguration $configuration) => $configuration->withTransactionOnConsoleCommands(true), ['transactionOnConsoleCommands' => true]);
        self::assertCopyChangesOnly($original, fn (DbalConfiguration $configuration) => $configuration->withClearAndFlushObjectManagerOnAsynchronousEndpoints(true), ['clearObjectManagerOnAsynchronousEndpoints' => true]);
        self::assertCopyChangesOnly($original, fn (DbalConfiguration $configuration) => $configuration->withClearAndFlushObjectManagerOnCommandBus(true), ['clearAndFlushObjectManagerOnCommandBus' => true]);
        self::assertCopyChangesOnly($original, fn (DbalConfiguration $configuration) => $configuration->withClearAndFlushObjectManagerOnProjectionBatch(true), ['clearAndFlushObjectManagerOnProjectionBatch' => true]);
        self::assertCopyChangesOnly($original, fn (DbalConfiguration $configuration) => $configuration->withFlushWhenPersisting(true), ['flushDuringPersisting' => true]);
        self::assertCopyChangesOnly($original, fn (DbalConfiguration $configuration) => $configuration->withDefaultConnectionReferenceNames(['other_connection']), ['defaultConnectionReferenceNames' => ['other_connection']]);
        self::assertCopyChangesOnly(
            $original,
            fn (DbalConfiguration $configuration) => $configuration->withDeduplication(true, 'other_deduplication_connection', 2000, 20, false),
            ['deduplicatedEnabled' => true, 'deduplicationConnectionReference' => 'other_deduplication_connection', 'minimumTimeToRemoveMessageInMilliseconds' => 2000, 'deduplicationRemovalBatchSize' => 20, 'deduplicationInMemory' => false],
        );
        self::assertCopyChangesOnly(
            $original,
            fn (DbalConfiguration $configuration) => $configuration->withDeadLetter(true, 'other_dead_letter_connection', false),
            ['deadLetterEnabled' => true, 'deadLetterConnectionReference' => 'other_dead_letter_connection', 'deadLetterInMemory' => false],
        );
        self::assertCopyChangesOnly(
            $original,
            fn (DbalConfiguration $configuration) => $configuration->withConsumerPositionTracking(true, 'other_position_connection'),
            ['consumerPositionTrackingEnabled' => true, 'consumerPositionTrackingConnectionReference' => 'other_position_connection'],
        );
        self::assertCopyChangesOnly(
            $original,
            fn (DbalConfiguration $configuration) => $configuration->withDocumentStore(true, false, 'other_document_store', false, 'other_document_store_connection', ['Wallet']),
            [
                'enableDbalDocumentStore' => true, 'inMemoryDocumentStore' => false, 'dbalDocumentStoreReference' => 'other_document_store',
                'enableDocumentStoreStateStoredRepository' => false, 'documentStoreConnectionReference' => 'other_document_store_connection', 'documentStoreRelatedAggregates' => ['Wallet'],
            ],
        );
    }

    private static function assertCopyChangesOnly(DbalConfiguration $original, Closure $with, array $changedFields): void
    {
        $observe = fn (DbalConfiguration $configuration): array => [
            ...get_object_vars($configuration),
            'deduplicationConnectionReference' => $configuration->getDeduplicationConnectionReference(),
            'deadLetterConnectionReference' => $configuration->getDeadLetterConnectionReference(),
        ];
        $fieldsBefore = $observe($original);

        $copy = $with($original);

        self::assertNotSame($original, $copy);
        self::assertEquals($fieldsBefore, $observe($original));
        self::assertEquals(array_replace($fieldsBefore, $changedFields), $observe($copy));
    }
}
