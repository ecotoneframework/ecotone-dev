<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Enqueue\Dbal\DbalConnectionFactory;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\DocumentStoreAggregate\Person;
use Test\Ecotone\Dbal\Fixture\DocumentStoreAggregate\PersonJsonConverter;
use Test\Ecotone\Dbal\Fixture\DocumentStoreAggregate\RegisterPerson;

/**
 * @internal
 */
final class DocumentStoreAggregateTest extends DbalMessagingTestCase
{
    public function test_support_for_document_store_aggregate(): void
    {
        $ecotone = $this->bootstrapEcotone();

        $ecotone->sendCommand(new RegisterPerson(100, 'Johnny'));

        self::assertEquals(
            'Johnny',
            $ecotone->sendQueryWithRouting('person.getName', metadata: ['aggregate.id' => 100])
        );
    }

    public function test_explicit_aggregate_config_for_document_store_aggregate(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [Person::class, PersonJsonConverter::class],
            containerOrAvailableServices: [new PersonJsonConverter(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()
                        ->withDocumentStore(enableDocumentStoreStandardRepository: true, documentStoreRelatedAggregates: [Person::class]),
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            addInMemoryStateStoredRepository: true
        );

        $ecotone->sendCommand(new RegisterPerson(100, 'Johnny'));

        self::assertEquals(
            'Johnny',
            $ecotone->sendQueryWithRouting('person.getName', metadata: ['aggregate.id' => 100])
        );
    }

    private function bootstrapEcotone(): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [new PersonJsonConverter(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withNamespaces([
                    'Test\Ecotone\Dbal\Fixture\DocumentStoreAggregate',
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            addInMemoryStateStoredRepository: false
        );
    }
}
