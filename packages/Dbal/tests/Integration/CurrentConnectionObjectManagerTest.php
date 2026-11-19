<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Dbal\Attribute\CurrentConnectionObjectManager;
use Ecotone\Api\Dbal\Attribute\DbalQuery;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\FetchMode;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Dbal\ManagerRegistryEmulator;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Support\LicensingException;
use Ecotone\Test\LicenceTesting;
use Fiber;
use RuntimeException;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\ORM\Person\Person;
use Test\Ecotone\Dbal\Fixture\ORM\Person\RegisterPerson;

/**
 * licence Apache-2.0
 * @internal
 */
final class CurrentConnectionObjectManagerTest extends DbalMessagingTestCase
{
    public function test_handler_of_an_application_without_tenants_stores_through_the_object_manager_of_its_connection(): void
    {
        $ecotone = $this->bootstrapPersonRegistration();

        $ecotone->sendCommandWithRouting('person.registerEntity', ['personId' => 1, 'name' => 'accepted']);

        $this->assertSame(['accepted'], $ecotone->getGateway(PersonsStoredThroughObjectManager::class)->names());
    }

    public function test_what_handler_stored_through_the_object_manager_is_rolled_back_with_the_message(): void
    {
        $ecotone = $this->bootstrapPersonRegistration();

        try {
            $ecotone->sendCommandWithRouting('person.registerEntity', ['personId' => 1, 'name' => 'refused', 'fail' => true]);
            $this->fail();
        } catch (RuntimeException) {
        }

        $this->assertSame([], $ecotone->getGateway(PersonsStoredThroughObjectManager::class)->names());
    }

    public function test_interleaved_executions_are_each_injected_with_the_object_manager_of_their_own_connection(): void
    {
        $ecotone = $this->bootstrapPersonRegistration();

        $suspended = new Fiber(function () use ($ecotone): void {
            try {
                $ecotone->sendCommandWithRouting('person.registerEntity', ['personId' => 1, 'name' => 'refused', 'suspend' => true, 'fail' => true]);
            } catch (RuntimeException) {
            }
        });
        $suspended->start();
        $ecotone->sendCommandWithRouting('person.registerEntity', ['personId' => 2, 'name' => 'accepted']);
        $suspended->resume();

        $this->assertSame(['accepted'], $ecotone->getGateway(PersonsStoredThroughObjectManager::class)->names());
    }

    public function test_object_manager_of_a_connection_registered_under_another_name_is_injected_by_that_name(): void
    {
        $ecotone = $this->bootstrapPersonRegistration();

        $ecotone->sendCommandWithRouting('person.registerEntityInReporting', ['personId' => 1, 'name' => 'reported']);

        $this->assertSame(['reported'], $ecotone->getGateway(PersonsStoredThroughObjectManager::class)->names());
    }

    public function test_object_manager_of_a_connection_registered_without_doctrine_orm_is_refused(): void
    {
        $handler = new class () {
            #[CommandHandler('person.registerEntity')]
            public function registerEntity(array $command, #[CurrentConnectionObjectManager] ObjectManager $objectManager): void
            {
            }
        };
        $ecotone = $this->bootstrapFlowTesting(
            [$handler::class],
            [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(self::databaseDsn())],
            [DbalConfiguration::createWithDefaults()],
            [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $this->expectExceptionMessage(
            '#[CurrentConnectionObjectManager] cannot inject the Doctrine ObjectManager of connection `Ecotone\\Api\\Dbal\\DbalConnectionFactory`: it is registered as Ecotone\\Api\\Dbal\\DbalConnectionFactory, which has no Doctrine ORM behind it. '
            . "Register the connection with Doctrine ORM: SymfonyConnectionReference::defaultManagerRegistry('default') in a Symfony application, or a service created with ManagerRegistryEmulator::fromDsnAndConfig(\$dsn, \$pathsToMapping). "
            . 'To run SQL on the connection of the handled message without Doctrine ORM, take #[CurrentConnection] Doctrine\\DBAL\\Connection instead.'
        );

        $ecotone->sendCommandWithRouting('person.registerEntity', ['personId' => 1, 'name' => 'refused']);
    }

    public function test_object_manager_naming_a_connection_that_is_not_registered_is_refused(): void
    {
        $handler = new class () {
            #[CommandHandler('person.registerEntity')]
            public function registerEntity(array $command, #[CurrentConnectionObjectManager('reporting')] ObjectManager $objectManager): void
            {
            }
        };

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            "#[CurrentConnectionObjectManager('reporting')] on " . $handler::class . '::registerEntity($objectManager) needs a connection registered as `reporting`, and there is none. '
            . 'Register it under that name: a service new Ecotone\\Api\\Dbal\\DbalConnectionFactory($dsn), SymfonyConnectionReference::createForConnection($connectionName, $referenceName), LaravelConnectionReference::create($connectionName, $referenceName) or TempestConnectionReference::create($configTag, $referenceName), '
            . 'or use #[CurrentConnectionObjectManager] without a name for the default connection `Ecotone\\Api\\Dbal\\DbalConnectionFactory`.'
        );

        EcotoneLite::bootstrap(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(self::databaseDsn())],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            pathToRootCatalog: __DIR__ . '/../../',
            licenceKey: LicenceTesting::VALID_LICENCE,
            namespaces: [],
            environment: 'prod',
        );
    }

    public function test_object_manager_of_the_current_connection_is_refused_without_an_enterprise_licence(): void
    {
        $handler = new class () {
            #[CommandHandler('person.registerEntity')]
            public function registerEntity(array $command, #[CurrentConnectionObjectManager] ObjectManager $objectManager): void
            {
            }
        };

        $this->expectException(LicensingException::class);
        $this->expectExceptionMessage(
            '#[CurrentConnectionObjectManager] on ' . $handler::class . '::registerEntity($objectManager) requires an Ecotone Enterprise licence. See https://docs.ecotone.tech/enterprise. '
            . 'Without the licence, take #[CurrentConnection] Doctrine\\DBAL\\Connection to run SQL on the connection of the handled message, or store the entity as an #[Aggregate] through DbalConfiguration::withDoctrineORMRepositories().'
        );

        $this->bootstrapFlowTesting(
            [$handler::class],
            [$handler, DbalConnectionFactory::class => ManagerRegistryEmulator::fromDsnAndConfig(self::databaseDsn(), [__DIR__ . '/../Fixture/ORM/Person'])],
            [DbalConfiguration::createWithDefaults()],
            [ModulePackageList::DBAL_PACKAGE],
        );
    }

    private function bootstrapPersonRegistration(): FlowTestSupport
    {
        $this->setupUserTable();
        $registration = new class () {
            #[CommandHandler('person.registerEntity')]
            public function registerEntity(array $command, #[CurrentConnectionObjectManager] EntityManagerInterface $objectManager): void
            {
                $objectManager->persist(Person::register(new RegisterPerson($command['personId'], $command['name']), false));
                $objectManager->flush();

                if ($command['suspend'] ?? false) {
                    Fiber::suspend();
                }
                if ($command['fail'] ?? false) {
                    throw new RuntimeException('Registration was refused');
                }
            }

            #[CommandHandler('person.registerEntityInReporting')]
            public function registerEntityInReporting(array $command, #[CurrentConnectionObjectManager('reporting')] ObjectManager $objectManager): void
            {
                $objectManager->persist(Person::register(new RegisterPerson($command['personId'], $command['name']), false));
                $objectManager->flush();
            }
        };

        return $this->bootstrapFlowTesting(
            [$registration::class, PersonsStoredThroughObjectManager::class],
            [
                $registration,
                DbalConnectionFactory::class => ManagerRegistryEmulator::fromDsnAndConfig(self::databaseDsn(), [__DIR__ . '/../Fixture/ORM/Person']),
                'reporting' => ManagerRegistryEmulator::fromDsnAndConfig(self::databaseDsn(), [__DIR__ . '/../Fixture/ORM/Person']),
            ],
            [DbalConfiguration::createWithDefaults()],
            [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}

interface PersonsStoredThroughObjectManager
{
    #[DbalQuery('SELECT name FROM persons ORDER BY person_id', fetchMode: FetchMode::FIRST_COLUMN)]
    public function names(): array;
}
