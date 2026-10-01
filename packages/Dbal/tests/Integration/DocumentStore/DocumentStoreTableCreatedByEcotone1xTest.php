<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\DocumentStore;

use Doctrine\DBAL\Schema\Table;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Gateway\DocumentStore;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Dbal\DocumentStore\DbalDocumentStore;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ModulePackageList;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class DocumentStoreTableCreatedByEcotone1xTest extends DbalMessagingTestCase
{
    public function test_writing_to_a_table_created_by_ecotone_1x_names_the_column_to_add_and_that_it_is_safe(): void
    {
        $this->createTableAsEcotone1xDid();
        $documentStore = $this->documentStore();

        try {
            $documentStore->addDocument('orders', 'o-1', '{"product":"milk"}');
            self::fail('Expected the missing version column to be reported');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString("The document store table 'ecotone_document_store' has no 'version' column", $exception->getMessage());
            self::assertStringContainsString('ALTER TABLE ecotone_document_store ADD COLUMN version INTEGER NOT NULL DEFAULT 1;', $exception->getMessage());
            self::assertStringContainsString('It is safe to run on a populated table', $exception->getMessage());
        }
    }

    public function test_reading_the_version_from_a_table_created_by_ecotone_1x_names_the_column_to_add(): void
    {
        $this->createTableAsEcotone1xDid();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('ALTER TABLE ecotone_document_store ADD COLUMN version INTEGER NOT NULL DEFAULT 1;');

        $this->documentStore()->getDocumentVersion('orders', 'o-1');
    }

    public function test_documents_stored_by_ecotone_1x_are_at_version_one_once_the_named_column_is_added(): void
    {
        $this->createTableAsEcotone1xDid();
        $this->getConnection()->insert(DbalDocumentStore::ECOTONE_DOCUMENT_STORE, [
            'collection' => 'orders',
            'document_id' => 'o-1',
            'document_type' => 'string',
            'document' => '{"product":"milk"}',
            'updated_at' => 1.0,
        ]);
        $this->getConnection()->executeStatement('ALTER TABLE ecotone_document_store ADD COLUMN version INTEGER NOT NULL DEFAULT 1');
        $documentStore = $this->documentStore();

        self::assertSame(1, $documentStore->getDocumentVersion('orders', 'o-1'));

        $documentStore->updateDocument('orders', 'o-1', '{"product":"water"}', expectedVersion: 1);

        self::assertJsonStringEqualsJsonString('{"product":"water"}', $documentStore->getDocument('orders', 'o-1'));
        self::assertSame(2, $documentStore->getDocumentVersion('orders', 'o-1'));
    }

    private function createTableAsEcotone1xDid(): void
    {
        $table = new Table(DbalDocumentStore::ECOTONE_DOCUMENT_STORE);
        $table->addColumn('collection', 'string', ['length' => 255]);
        $table->addColumn('document_id', 'string', ['length' => 255]);
        $table->addColumn('document_type', 'text');
        $table->addColumn('document', 'json');
        $table->addColumn('updated_at', 'float', ['length' => 53]);
        $table->setPrimaryKey(['collection', 'document_id']);

        $this->getConnection()->createSchemaManager()->createTable($table);
    }

    private function documentStore(): DocumentStore
    {
        return $this->bootstrapFlowTesting(
            containerOrAvailableServices: [DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE])
                ->withExtensionObjects([DbalConfiguration::createWithDefaults()->withDocumentStore()]),
        )->getGateway(DocumentStore::class);
    }
}
