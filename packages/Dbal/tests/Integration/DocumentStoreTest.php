<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Store\Document\DocumentStore;
use Enqueue\Dbal\DbalConnectionFactory;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\DocumentStoreAggregate\PersonJsonConverter;

/**
 * @internal
 */
final class DocumentStoreTest extends DbalMessagingTestCase
{
    public function test_support_for_document_store(): void
    {
        $documentStore = $this->bootstrapDocumentStore();

        $documentStore->addDocument('shop', '100', $this->convertOrderToJson('milk'));
        $documentStore->updateDocument('shop', '100', $this->convertOrderToJson('water'));
        $this->assertOrder($documentStore, 'shop', '100', 'water');

        $documentStore->upsertDocument('shop', '101', $this->convertOrderToJson('coffee'));
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
        return (EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [new PersonJsonConverter(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames([ModulePackageList::JMS_CONVERTER_PACKAGE, ModulePackageList::AMQP_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE]),
            pathToRootCatalog: __DIR__ . '/../../',
        ))->getGateway(DocumentStore::class)
        ;
    }

    private function convertOrderToJson(string $order): string
    {
        return json_encode(['data' => $order]);
    }
}
