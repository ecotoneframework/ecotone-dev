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
use Test\Ecotone\Dbal\Fixture\ORM\Person\Person;
use Test\Ecotone\Dbal\Fixture\ORM\Person\RegisterPerson;

/**
 * @internal
 */
final class ORMTest extends DbalMessagingTestCase
{
    public function test_support_for_orm(): void
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
        $this->setupUserTable();

        return EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [DbalConnectionFactory::class => $this->getORMConnectionFactory([__DIR__.'/../Fixture/ORM/Person'])],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames([ModulePackageList::JMS_CONVERTER_PACKAGE, ModulePackageList::AMQP_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withNamespaces([
                    'Test\Ecotone\Dbal\Fixture\ORM\Person',
                ])
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()
                        ->withDoctrineORMRepositories(true, [Person::class]),
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            addInMemoryStateStoredRepository: false
        );
    }
}
