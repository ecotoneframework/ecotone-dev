<?php

namespace Test\Ecotone\Dbal\Integration\DocumentStore;

use Ecotone\Dbal\DocumentStore\DbalDocumentStore;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Store\Document\DocumentException;
use Ecotone\Messaging\Store\Document\DocumentStore;
use Enqueue\Dbal\DbalConnectionFactory;

use function json_decode;
use function json_encode;

use stdClass;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class DbalDocumentStoreTest extends DbalMessagingTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->cleanUpTables();
    }

    public function tearDown(): void
    {
        parent::tearDown();
        $this->cleanUpTables();
    }

    public function test_adding_document_to_collection()
    {
        $ecotone = $this->bootstrapEcotone();
        $documentStore = $ecotone->getGateway(DocumentStore::class);

        $this->assertEquals(0, $documentStore->countDocuments('users'));

        $documentStore->addDocument('users', '123', '{"name":"Johny"}');

        $this->assertJsons('{"name":"Johny"}', $documentStore->getDocument('users', '123'));
        $this->assertEquals(1, $documentStore->countDocuments('users'));
    }

    public function test_finding_document_to_collection()
    {
        $ecotone = $this->bootstrapEcotone();
        $documentStore = $ecotone->getGateway(DocumentStore::class);

        $this->assertNull($documentStore->findDocument('users', '123'));

        $documentStore->addDocument('users', '123', '{"name":"Johny"}');

        $this->assertJsons('{"name":"Johny"}', $documentStore->findDocument('users', '123'));
    }

    public function test_updating_document()
    {
        $ecotone = $this->bootstrapEcotone();
        $documentStore = $ecotone->getGateway(DocumentStore::class);

        $this->assertEquals(0, $documentStore->countDocuments('users'));

        $documentStore->addDocument('users', '123', '{"name":"Johny"}');
        $documentStore->updateDocument('users', '123', '{"name":"Franco"}');

        $this->assertJsons('{"name":"Franco"}', $documentStore->getDocument('users', '123'));
    }

    public function test_updating_document_with_same_content()
    {
        $ecotone = $this->bootstrapEcotone();
        $documentStore = $ecotone->getGateway(DocumentStore::class);

        $this->assertEquals(0, $documentStore->countDocuments('users'));

        $documentStore->addDocument('users', '123', '{"name":"Johny"}');
        $documentStore->updateDocument('users', '123', '{"name":"Johny"}');

        $this->assertJsons('{"name":"Johny"}', $documentStore->getDocument('users', '123'));
    }

    public function test_adding_document_as_object_should_return_object()
    {
        $converter = new #[\Ecotone\Messaging\Attribute\MediaTypeConverter] class () implements \Ecotone\Messaging\Conversion\Converter {
            public function convert($source, \Ecotone\Messaging\Handler\Type $sourceType, \Ecotone\Messaging\Conversion\MediaType $sourceMediaType, \Ecotone\Messaging\Handler\Type $targetType, \Ecotone\Messaging\Conversion\MediaType $targetMediaType)
            {
                if ($sourceMediaType->isCompatibleWith(\Ecotone\Messaging\Conversion\MediaType::createApplicationXPHP())) {
                    return '{"name":"johny"}';
                }

                return new stdClass();
            }

            public function matches(\Ecotone\Messaging\Handler\Type $sourceType, \Ecotone\Messaging\Conversion\MediaType $sourceMediaType, \Ecotone\Messaging\Handler\Type $targetType, \Ecotone\Messaging\Conversion\MediaType $targetMediaType): bool
            {
                return ($sourceType->toString() === 'stdClass'
                        && $targetMediaType->isCompatibleWith(\Ecotone\Messaging\Conversion\MediaType::createApplicationJson()))
                    || ($sourceMediaType->isCompatibleWith(\Ecotone\Messaging\Conversion\MediaType::createApplicationJson())
                        && $targetType->toString() === 'stdClass');
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [get_class($converter)],
            containerOrAvailableServices: [DbalConnectionFactory::class => $this->getConnectionFactory(), $converter],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
                ->withNamespaces([]),
            pathToRootCatalog: __DIR__ . '/../../..',
            addInMemoryStateStoredRepository: false,
        );
        $documentStore = $ecotone->getGateway(DocumentStore::class);

        $this->assertEquals(0, $documentStore->countDocuments('users'));

        $documentStore->addDocument('users', '123', new stdClass());

        $this->assertEquals(new stdClass(), $documentStore->getDocument('users', '123'));
    }

    public function test_adding_document_as_collection_of_objects_should_return_object()
    {
        $converter = new #[\Ecotone\Messaging\Attribute\MediaTypeConverter] class () implements \Ecotone\Messaging\Conversion\Converter {
            public function convert($source, \Ecotone\Messaging\Handler\Type $sourceType, \Ecotone\Messaging\Conversion\MediaType $sourceMediaType, \Ecotone\Messaging\Handler\Type $targetType, \Ecotone\Messaging\Conversion\MediaType $targetMediaType)
            {
                if ($sourceMediaType->isCompatibleWith(\Ecotone\Messaging\Conversion\MediaType::createApplicationXPHP())) {
                    return '[{"name":"johny"},{"name":"franco"}]';
                }

                return [new stdClass(), new stdClass()];
            }

            public function matches(\Ecotone\Messaging\Handler\Type $sourceType, \Ecotone\Messaging\Conversion\MediaType $sourceMediaType, \Ecotone\Messaging\Handler\Type $targetType, \Ecotone\Messaging\Conversion\MediaType $targetMediaType): bool
            {
                return ($sourceType->toString() === 'array<stdClass>'
                        && $targetMediaType->isCompatibleWith(\Ecotone\Messaging\Conversion\MediaType::createApplicationJson()))
                    || ($sourceMediaType->isCompatibleWith(\Ecotone\Messaging\Conversion\MediaType::createApplicationJson())
                        && $targetType->toString() === 'array<stdClass>');
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [get_class($converter)],
            containerOrAvailableServices: [DbalConnectionFactory::class => $this->getConnectionFactory(), $converter],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
                ->withNamespaces([]),
            pathToRootCatalog: __DIR__ . '/../../..',
            addInMemoryStateStoredRepository: false,
        );
        $documentStore = $ecotone->getGateway(DocumentStore::class);

        $document = [new stdClass(), new stdClass()];

        $this->assertEquals(0, $documentStore->countDocuments('users'));

        $documentStore->addDocument('users', '123', $document);

        $this->assertEquals([$document], $documentStore->getAllDocuments('users'));
    }

    public function test_adding_document_as_array_should_return_array()
    {
        $converter = new #[\Ecotone\Messaging\Attribute\MediaTypeConverter] class () implements \Ecotone\Messaging\Conversion\Converter {
            public function convert($source, \Ecotone\Messaging\Handler\Type $sourceType, \Ecotone\Messaging\Conversion\MediaType $sourceMediaType, \Ecotone\Messaging\Handler\Type $targetType, \Ecotone\Messaging\Conversion\MediaType $targetMediaType)
            {
                if ($sourceMediaType->isCompatibleWith(\Ecotone\Messaging\Conversion\MediaType::createApplicationXPHP())) {
                    return '[1,2,5]';
                }

                return [1, 2, 5];
            }

            public function matches(\Ecotone\Messaging\Handler\Type $sourceType, \Ecotone\Messaging\Conversion\MediaType $sourceMediaType, \Ecotone\Messaging\Handler\Type $targetType, \Ecotone\Messaging\Conversion\MediaType $targetMediaType): bool
            {
                return ($sourceType->isIterable() && ! $sourceType->isClassOrInterface()
                        && $targetMediaType->isCompatibleWith(\Ecotone\Messaging\Conversion\MediaType::createApplicationJson()))
                    || ($sourceMediaType->isCompatibleWith(\Ecotone\Messaging\Conversion\MediaType::createApplicationJson())
                        && $targetType->isIterable() && ! $targetType->isClassOrInterface());
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [get_class($converter)],
            containerOrAvailableServices: [DbalConnectionFactory::class => $this->getConnectionFactory(), $converter],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
                ->withNamespaces([]),
            pathToRootCatalog: __DIR__ . '/../../..',
            addInMemoryStateStoredRepository: false,
        );
        $documentStore = $ecotone->getGateway(DocumentStore::class);

        $document = [1, 2, 5];

        $this->assertEquals(0, $documentStore->countDocuments('users'));

        $documentStore->addDocument('users', '123', $document);

        $this->assertEquals($document, $documentStore->getDocument('users', '123'));
    }

    public function test_adding_non_json_document_should_fail()
    {
        if ($this->isUsingSqlite()) {
            $this->markTestSkipped('SQLite does not validate JSON at the database level');
        }

        $ecotone = $this->bootstrapEcotone();
        $documentStore = $ecotone->getGateway(DocumentStore::class);

        $this->assertEquals(0, $documentStore->countDocuments('users'));

        $this->expectException(DocumentException::class);

        $documentStore->addDocument('users', '123', '{"name":');
    }

    public function test_deleting_document()
    {
        $ecotone = $this->bootstrapEcotone();
        $documentStore = $ecotone->getGateway(DocumentStore::class);

        $documentStore->addDocument('users', '123', '{"name":"Johny"}');
        $documentStore->addDocument('companies', '123', '{"name":"Document Stores, INC."}');

        $this->assertEquals(1, $documentStore->countDocuments('users'));
        $this->assertEquals(1, $documentStore->countDocuments('companies'));

        $documentStore->deleteDocument('users', '123');

        $this->assertEquals(0, $documentStore->countDocuments('users'));
        $this->assertEquals(1, $documentStore->countDocuments('companies'));
    }

    public function test_deleting_non_existing_document()
    {
        $ecotone = $this->bootstrapEcotone();
        $documentStore = $ecotone->getGateway(DocumentStore::class);

        $documentStore->deleteDocument('users', '123');

        $this->assertEquals(0, $documentStore->countDocuments('users'));
    }

    public function test_throwing_exception_if_looking_for_non_existing_document()
    {
        $ecotone = $this->bootstrapEcotone();
        $documentStore = $ecotone->getGateway(DocumentStore::class);

        $this->expectException(DocumentException::class);

        $documentStore->getDocument('users', '123');
    }

    public function test_throwing_exception_if_looking_for_previously_existing_document()
    {
        $ecotone = $this->bootstrapEcotone();
        $documentStore = $ecotone->getGateway(DocumentStore::class);

        $documentStore->addDocument('users', '123', '{"name":"Johny"}');
        $documentStore->deleteDocument('users', '123');

        $this->expectException(DocumentException::class);

        $documentStore->getDocument('users', '123');
    }

    public function test_dropping_collection()
    {
        $ecotone = $this->bootstrapEcotone();
        $documentStore = $ecotone->getGateway(DocumentStore::class);
        $documentStore->addDocument('users', '123', '{"name":"Johny"}');
        $documentStore->addDocument('users', '124', '{"name":"Johny"}');
        $documentStore->addDocument('companies', '123', '{"name":"Document Stores, INC."}');
        $documentStore->addDocument('companies', '124', '{"name":"Document Stores, INC."}');

        $documentStore->dropCollection('users');

        $this->assertEquals(0, $documentStore->countDocuments('users'));
        $this->assertEquals(2, $documentStore->countDocuments('companies'));
    }

    public function test_retrieving_whole_collection()
    {
        $ecotone = $this->bootstrapEcotone();
        $documentStore = $ecotone->getGateway(DocumentStore::class);

        $this->assertEquals([], $documentStore->getAllDocuments('users'));

        $documentStore->addDocument('users', '123', '{"name":"Johny"}');
        $documentStore->addDocument('users', '124', '{"name":"Franco"}');

        $this->assertEquals([
            '{"name":"Johny"}',
            '{"name":"Franco"}',
        ], array_map(fn (string $document) => json_encode(json_decode($document, true)), $documentStore->getAllDocuments('users')));
    }

    public function test_retrieving_whole_collection_of_objects()
    {
        $converter = new #[\Ecotone\Messaging\Attribute\MediaTypeConverter] class () implements \Ecotone\Messaging\Conversion\Converter {
            public function convert($source, \Ecotone\Messaging\Handler\Type $sourceType, \Ecotone\Messaging\Conversion\MediaType $sourceMediaType, \Ecotone\Messaging\Handler\Type $targetType, \Ecotone\Messaging\Conversion\MediaType $targetMediaType)
            {
                if ($sourceMediaType->isCompatibleWith(\Ecotone\Messaging\Conversion\MediaType::createApplicationXPHP())) {
                    return '{"name":"johny"}';
                }

                return new stdClass();
            }

            public function matches(\Ecotone\Messaging\Handler\Type $sourceType, \Ecotone\Messaging\Conversion\MediaType $sourceMediaType, \Ecotone\Messaging\Handler\Type $targetType, \Ecotone\Messaging\Conversion\MediaType $targetMediaType): bool
            {
                return ($sourceType->toString() === 'stdClass'
                        && $targetMediaType->isCompatibleWith(\Ecotone\Messaging\Conversion\MediaType::createApplicationJson()))
                    || ($sourceMediaType->isCompatibleWith(\Ecotone\Messaging\Conversion\MediaType::createApplicationJson())
                        && $targetType->toString() === 'stdClass');
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [get_class($converter)],
            containerOrAvailableServices: [DbalConnectionFactory::class => $this->getConnectionFactory(), $converter],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
                ->withNamespaces([]),
            pathToRootCatalog: __DIR__ . '/../../..',
            addInMemoryStateStoredRepository: false,
        );
        $documentStore = $ecotone->getGateway(DocumentStore::class);

        $this->assertEquals(0, $documentStore->countDocuments('users'));

        $documentStore->addDocument('users', '123', new stdClass());
        $documentStore->addDocument('users', '124', new stdClass());

        $this->assertEquals([new stdClass(), new stdClass()], $documentStore->getAllDocuments('users'));
    }

    public function test_dropping_non_existing_collection()
    {
        $ecotone = $this->bootstrapEcotone();
        $documentStore = $ecotone->getGateway(DocumentStore::class);

        $documentStore->dropCollection('users');

        $this->assertEquals(0, $documentStore->countDocuments('users'));
    }

    public function test_replacing_document()
    {
        $ecotone = $this->bootstrapEcotone();
        $documentStore = $ecotone->getGateway(DocumentStore::class);

        $this->assertEquals(0, $documentStore->countDocuments('users'));

        $documentStore->addDocument('users', '123', '{"name":"Johny"}');
        $documentStore->upsertDocument('users', '123', '{"name":"Johny Mac"}');

        $this->assertJsons('{"name":"Johny Mac"}', $documentStore->getDocument('users', '123'));
    }

    public function test_upserting_new_document()
    {
        $ecotone = $this->bootstrapEcotone();
        $documentStore = $ecotone->getGateway(DocumentStore::class);

        $this->assertEquals(0, $documentStore->countDocuments('users'));

        $documentStore->upsertDocument('users', '123', '{"name":"Johny Mac"}');

        $this->assertJsons('{"name":"Johny Mac"}', $documentStore->getDocument('users', '123'));
    }

    public function test_excepting_if_trying_to_add_document_twice()
    {
        $ecotone = $this->bootstrapEcotone();
        $documentStore = $ecotone->getGateway(DocumentStore::class);

        $this->expectException(DocumentException::class);

        $documentStore->addDocument('users', '123', '{"name":"Johny"}');
        $documentStore->addDocument('users', '123', '{"name":"Johny Mac"}');
    }

    private function bootstrapEcotone(): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
                ->withNamespaces([]),
            pathToRootCatalog: __DIR__ . '/../../..',
            addInMemoryStateStoredRepository: false,
        );
    }

    private function cleanUpTables(): void
    {
        $connection = $this->getConnection();
        $schemaManager = method_exists($connection, 'createSchemaManager')
            ? $connection->createSchemaManager()
            : $connection->getSchemaManager();

        foreach ($schemaManager->listTableNames() as $tableName) {
            if ($tableName === DbalDocumentStore::ECOTONE_DOCUMENT_STORE) {
                $schemaManager->dropTable($tableName);
            }
        }
    }

    private function assertJsons(string $expectedJson, string $givenJson): void
    {
        $this->assertEquals($expectedJson, json_encode(json_decode($givenJson, true)));
    }
}
