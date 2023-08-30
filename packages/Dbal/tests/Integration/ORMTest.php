<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Enqueue\Dbal\DbalConnectionFactory;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\ORM\Person\Person;
use Test\Ecotone\Dbal\Fixture\ORM\Person\RegisterPerson;
use Test\Ecotone\Dbal\Fixture\ORM\PersonRepository\ORMPersonRepository;
use Test\Ecotone\Dbal\Fixture\ORM\PersonRepository\RegisterPersonService;
use Test\Ecotone\Dbal\Fixture\ORM\SynchronousEventHandler\SaveMultipleEntitiesHandler;

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

    public function test_flushing_object_manager_on_command_bus()
    {
        $this->setupUserTable();
        $entityManager = $this->setupEntityManagerFor([__DIR__.'/../Fixture/ORM/Person']);
        $ORMPersonRepository = new ORMPersonRepository($entityManager);

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [
                DbalConnectionFactory::class => $this->getORMConnectionFactory($entityManager),
                ORMPersonRepository::class => $ORMPersonRepository,
                RegisterPersonService::class => new RegisterPersonService(),
                SaveMultipleEntitiesHandler::class => new SaveMultipleEntitiesHandler(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames([ModulePackageList::JMS_CONVERTER_PACKAGE, ModulePackageList::AMQP_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withNamespaces([
                    'Test\Ecotone\Dbal\Fixture\ORM\PersonRepository',
                    'Test\Ecotone\Dbal\Fixture\ORM\SynchronousEventHandler',
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            addInMemoryStateStoredRepository: false
        );

        $ecotone->sendCommand(new RegisterPerson(100, 'Johnny'));
        $ecotone->sendCommandWithRoutingKey(Person::RENAME_COMMAND, 'Paul', metadata: ['aggregate.id' => 100]);

        self::assertEquals(
            'Paul',
            $ORMPersonRepository->get(100)->getName()
        );
        self::assertEquals(
            'Paul2',
            $ORMPersonRepository->get(101)->getName()
        );
    }

    public function test_disabling_flushing_object_manager_on_command_bus()
    {
        $this->setupUserTable();
        $entityManager = $this->setupEntityManagerFor([__DIR__.'/../Fixture/ORM/Person']);
        $ORMPersonRepository = new ORMPersonRepository($entityManager);

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [
                DbalConnectionFactory::class => $this->getORMConnectionFactory($entityManager),
                ORMPersonRepository::class => $ORMPersonRepository,
                RegisterPersonService::class => new RegisterPersonService(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames([ModulePackageList::JMS_CONVERTER_PACKAGE, ModulePackageList::AMQP_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withNamespaces([
                    'Test\Ecotone\Dbal\Fixture\ORM\PersonRepository',
                ])
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()
                        ->withClearAndFlushObjectManagerOnCommandBus(false),
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            addInMemoryStateStoredRepository: false
        );

        $ecotone->sendCommand(new RegisterPerson(100, 'Johnny'));
        $entityManager->clear();

        $this->expectException(InvalidArgumentException::class);

        $ORMPersonRepository->get(100);
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
