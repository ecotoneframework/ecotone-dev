<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Enqueue\Dbal\DbalConnectionFactory;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
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

    private function bootstrapEcotone(): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [new PersonJsonConverter(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames([ModulePackageList::JMS_CONVERTER_PACKAGE, ModulePackageList::AMQP_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withNamespaces([
                    'Test\Ecotone\Dbal\Fixture\DocumentStoreAggregate',
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
        );
    }
}
