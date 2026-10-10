<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Closure;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Gateway\DocumentStore;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Dbal\Database\DeadLetterTableManager;
use Ecotone\Dbal\Database\DeduplicationTableManager;
use Ecotone\Dbal\Database\DocumentStoreTableManager;
use Ecotone\Dbal\Database\MissingTableInstructions;
use Ecotone\Dbal\Deduplication\DbalHandledMessages;
use Ecotone\Dbal\DocumentStore\DbalDocumentStore;
use Ecotone\Dbal\Recoverability\DbalDeadLetterStore;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Support\MessageBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class MissingTableRefusalTest extends DbalMessagingTestCase
{
    public static function documentStoreOperations(): iterable
    {
        yield 'add' => [fn (DocumentStore $store) => $store->addDocument('orders', 'o-1', '{"product":"milk"}')];
        yield 'update' => [fn (DocumentStore $store) => $store->updateDocument('orders', 'o-1', '{"product":"milk"}', 1)];
        yield 'upsert' => [fn (DocumentStore $store) => $store->upsertDocument('orders', 'o-1', '{"product":"milk"}', 0)];
        yield 'get' => [fn (DocumentStore $store) => $store->getDocument('orders', 'o-1')];
        yield 'find' => [fn (DocumentStore $store) => $store->findDocument('orders', 'o-1')];
        yield 'get version' => [fn (DocumentStore $store) => $store->getDocumentVersion('orders', 'o-1')];
        yield 'get all' => [fn (DocumentStore $store) => $store->getAllDocuments('orders')];
        yield 'count' => [fn (DocumentStore $store) => $store->countDocuments('orders')];
        yield 'delete' => [fn (DocumentStore $store) => $store->deleteDocument('orders', 'o-1')];
        yield 'drop collection' => [fn (DocumentStore $store) => $store->dropCollection('orders')];
    }

    #[DataProvider('documentStoreOperations')]
    public function test_every_document_store_operation_names_the_setup_when_its_table_is_missing(Closure $operation): void
    {
        $ecotone = $this->bootstrapWithoutTables(DbalConfiguration::createWithDefaults());

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(MissingTableInstructions::build(DocumentStoreTableManager::FEATURE_NAME, DbalDocumentStore::ECOTONE_DOCUMENT_STORE, null));

        $operation($ecotone->getGateway(DocumentStore::class));
    }

    public static function deadLetterOperations(): iterable
    {
        yield 'store' => [fn (DeadLetterGateway $deadLetter) => $deadLetter->store(MessageBuilder::withPayload('failed')->build())];
        yield 'list' => [fn (DeadLetterGateway $deadLetter) => $deadLetter->list(10, 0)];
        yield 'count' => [fn (DeadLetterGateway $deadLetter) => $deadLetter->count()];
        yield 'show' => [fn (DeadLetterGateway $deadLetter) => $deadLetter->show('m-1')];
        yield 'replay' => [fn (DeadLetterGateway $deadLetter) => $deadLetter->replay('m-1')];
        yield 'replay all' => [fn (DeadLetterGateway $deadLetter) => $deadLetter->replayAll()];
        yield 'delete' => [fn (DeadLetterGateway $deadLetter) => $deadLetter->delete('m-1')];
        yield 'delete all' => [fn (DeadLetterGateway $deadLetter) => $deadLetter->deleteAll()];
    }

    #[DataProvider('deadLetterOperations')]
    public function test_every_dead_letter_operation_names_the_setup_when_its_table_is_missing(Closure $operation): void
    {
        $ecotone = $this->bootstrapWithoutTables(DbalConfiguration::createWithDefaults());

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(MissingTableInstructions::build(DeadLetterTableManager::FEATURE_NAME, DbalDeadLetterStore::DEFAULT_DEAD_LETTER_TABLE, null));

        $operation($ecotone->getGateway(DeadLetterGateway::class));
    }

    public function test_removing_expired_deduplication_entries_names_the_setup_when_deduplication_is_used_and_its_table_is_missing(): void
    {
        $ecotone = $this->bootstrapWithoutTables(DbalConfiguration::createWithDefaults()->withDeduplication(true));

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(MissingTableInstructions::build(DeduplicationTableManager::FEATURE_NAME, DbalHandledMessages::DEFAULT_DEDUPLICATION_TABLE, null));

        $ecotone->runConsoleCommand('ecotone:deduplication:remove-expired-messages', []);
    }

    public function test_removing_expired_deduplication_entries_does_nothing_when_deduplication_is_not_used(): void
    {
        $ecotone = $this->bootstrapWithoutTables(DbalConfiguration::createWithDefaults()->withDeduplication(false));

        $ecotone->runConsoleCommand('ecotone:deduplication:remove-expired-messages', []);

        self::assertFalse(self::checkIfTableExists($this->getConnection(), DbalHandledMessages::DEFAULT_DEDUPLICATION_TABLE));
    }

    private function bootstrapWithoutTables(DbalConfiguration $dbalConfiguration): FlowTestSupport
    {
        return $this->bootstrapFlowTesting(
            containerOrAvailableServices: [DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE])
                ->withExtensionObjects([$dbalConfiguration]),
            testConfiguration: TestConfiguration::createWithDefaults()->withDatabaseTablesSetUpOnBootstrap(false),
        );
    }
}
