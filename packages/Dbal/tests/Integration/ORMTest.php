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
use Test\Ecotone\Dbal\Fixture\ORM\Person;
use Test\Ecotone\Dbal\Fixture\ORM\RegisterPerson;

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
        $connection = $this->getConnection();

        if (! $this->checkIfTableExists($connection, 'persons')) {
            $connection->executeStatement(<<<SQL
                    CREATE TABLE persons (
                        person_id INTEGER PRIMARY KEY,
                        name VARCHAR(255)
                    )
                SQL);
        }

        return EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [DbalConnectionFactory::class => $this->getORMConnectionFactory([__DIR__.'/../Fixture/ORM'])],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames([ModulePackageList::JMS_CONVERTER_PACKAGE, ModulePackageList::AMQP_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withNamespaces([
                    'Test\Ecotone\Dbal\Fixture\ORM',
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
