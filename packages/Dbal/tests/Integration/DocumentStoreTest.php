<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Gateway\DocumentStore;
use Ecotone\Messaging\Store\Document\DocumentException;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\DocumentStoreAggregate\PersonJsonConverter;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class DocumentStoreTest extends DbalMessagingTestCase
{
    public function test_support_for_document_store(): void
    {
        $documentStore = $this->bootstrapDocumentStore();

        $documentStore->addDocument('shop', '100', $this->convertOrderToJson('milk'));
        $documentStore->updateDocument('shop', '100', $this->convertOrderToJson('water'), expectedVersion: 1);
        $this->assertOrder($documentStore, 'shop', '100', 'water');

        $documentStore->upsertDocument('shop', '101', $this->convertOrderToJson('coffee'), expectedVersion: 0);
        $this->assertOrder($documentStore, 'shop', '101', 'coffee');

        self::assertEquals(2, $documentStore->countDocuments('shop'));

        $documentStore->deleteDocument('shop', '100');

        self::assertEquals(1, $documentStore->countDocuments('shop'));
    }

    public function test_support_for_document_store_using_different_collections(): void
    {
        $documentStore = $this->bootstrapDocumentStore();

        $documentStore->addDocument('milky_shop', '100', $this->convertOrderToJson('milk'));
        $this->assertOrder($documentStore, 'milky_shop', '100', 'milk');

        self::assertEquals(1, $documentStore->countDocuments('milky_shop'));

        $documentStore->addDocument('meat_shop', '100', $this->convertOrderToJson('ham'));
        $this->assertOrder($documentStore, 'meat_shop', '100', 'ham');

        self::assertEquals(1, $documentStore->countDocuments('meat_shop'));

    }

    public function test_a_document_that_cannot_be_read_back_is_refused_naming_its_id_and_collection(): void
    {
        $documentStore = $this->bootstrapDocumentStoreWithJmsConverter();
        $documentStore->addDocument('unreadable_documents', 'document-1', new UnreadableDocumentForDocumentStore());

        try {
            $documentStore->getDocument('unreadable_documents', 'document-1');
            self::fail('A document JMS cannot read back was returned');
        } catch (DocumentException $exception) {
            self::assertStringContainsString("Document with id 'document-1' in collection 'unreadable_documents' can not be converted from JSON to PHP object", $exception->getMessage());
        }
    }

    public function test_reading_a_whole_collection_names_the_document_that_cannot_be_read_back(): void
    {
        $documentStore = $this->bootstrapDocumentStoreWithJmsConverter();
        $documentStore->addDocument('unreadable_documents', 'document-1', new UnreadableDocumentForDocumentStore());

        try {
            $documentStore->getAllDocuments('unreadable_documents');
            self::fail('A document JMS cannot read back was returned');
        } catch (DocumentException $exception) {
            self::assertStringContainsString("Document with id 'document-1' in collection 'unreadable_documents' can not be converted from JSON to PHP object", $exception->getMessage());
        }
    }

    private function assertOrder(DocumentStore $documentStore, string $shopName, string $orderId, string $order): void
    {
        self::assertEquals(
            $this->convertOrderToJson($order),
            json_encode(json_decode($documentStore->getDocument($shopName, $orderId)))
        );
        self::assertEquals(
            $this->convertOrderToJson($order),
            json_encode(json_decode($documentStore->findDocument($shopName, $orderId), true))
        );
    }

    private function bootstrapDocumentStore(): DocumentStore
    {
        return ($this->bootstrapFlowTesting(
            containerOrAvailableServices: [new PersonJsonConverter(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            pathToRootCatalog: __DIR__ . '/../../',
            environment: 'prod',
        ))->getGateway(DocumentStore::class)
        ;
    }

    private function bootstrapDocumentStoreWithJmsConverter(): DocumentStore
    {
        return $this->bootstrapFlowTesting(
            classesToResolve: [UnreadableDocumentForDocumentStore::class],
            containerOrAvailableServices: [DbalConnectionFactory::class => $this->getConnectionFactory()],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ModulePackageList::JMS_CONVERTER_PACKAGE],
        )->getGateway(DocumentStore::class);
    }

    private function convertOrderToJson(string $order): string
    {
        return json_encode(['data' => $order]);
    }
}

final class UnreadableDocumentForDocumentStore
{
    private ?array $notes = null;
}
