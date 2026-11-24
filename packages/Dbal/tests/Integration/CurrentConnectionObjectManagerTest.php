<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Dbal\Attribute\CurrentConnectionObjectManager;
use Ecotone\Api\Dbal\Attribute\DbalQuery;
use Ecotone\Api\Dbal\DbalConnection;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\FetchMode;
use Ecotone\Api\Dbal\ManagerRegistryProvider;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
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
        $this->skipWhenTwoConnectionsCannotWriteAtOnce();

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

        $this->assertSame(['reported'], $ecotone->getGateway(PersonsOfReportingStoredThroughObjectManager::class)->names());
        $this->assertSame([], $ecotone->getGateway(PersonsStoredThroughObjectManager::class)->names());
    }

    public function test_object_manager_is_the_one_of_the_named_connection_when_the_registry_has_another_default_manager(): void
    {
        $ecotone = $this->bootstrapPersonRegistrationOnARegistryOfTwoManagers();

        $ecotone->sendCommandWithRouting('person.registerEntityInReporting', ['personId' => 1, 'name' => 'reported']);

        $this->assertSame(['reported'], $ecotone->getGateway(PersonsOfReportingStoredThroughObjectManager::class)->names());
        $this->assertSame([], $ecotone->getGateway(PersonsStoredThroughObjectManager::class)->names());
    }

    public function test_object_manager_of_a_connection_no_entity_manager_of_the_registry_uses_is_refused(): void
    {
        $ecotone = $this->bootstrapPersonRegistrationOnARegistryOfTwoManagers();

        $this->expectExceptionMessage(
            '#[CurrentConnectionObjectManager] cannot inject the Doctrine ObjectManager of connection `archive` (Doctrine connection `archive_database`): none of the entity managers of its ManagerRegistry (`primary`, `reporting`) uses that connection. '
            . 'Configure an entity manager on the connection (in Symfony: an entry of doctrine.orm.entity_managers with `connection: archive_database`), '
            . 'or take #[CurrentConnection] Doctrine\\DBAL\\Connection to run SQL on it without Doctrine ORM.'
        );

        $ecotone->sendCommandWithRouting('person.registerEntityInArchive', ['personId' => 1, 'name' => 'archived']);
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
            . 'Register the connection with Doctrine ORM: SymfonyConnectionReference::defaultManagerRegistry($connectionName) or SymfonyConnectionReference::createForManagerRegistry($connectionName, referenceName: $referenceName) in a Symfony application, or a service created with Ecotone\\Api\\Dbal\\DbalConnection::createForManagerRegistry($managerRegistry, $connectionName). To give every coroutine an ObjectManager of its own, register a service created with Ecotone\\Api\\Dbal\\ManagerRegistryProvider::fromDsnAndConfig($dsn, $pathsToMapping). '
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
            . 'Register it under that name with Doctrine ORM: SymfonyConnectionReference::createForManagerRegistry($connectionName, referenceName: $referenceName) in a Symfony application, or a service created with Ecotone\\Api\\Dbal\\DbalConnection::createForManagerRegistry($managerRegistry, $connectionName) or, to give every coroutine an ObjectManager of its own, with Ecotone\\Api\\Dbal\\ManagerRegistryProvider::fromDsnAndConfig($dsn, $pathsToMapping), '
            . 'or use #[CurrentConnectionObjectManager] without a name for the connection messages are handled on, `Ecotone\\Api\\Dbal\\DbalConnectionFactory`.'
        );

        EcotoneLite::bootstrap(
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
            [$handler, DbalConnectionFactory::class => ManagerRegistryProvider::fromDsnAndConfig(self::databaseDsn(), [__DIR__ . '/../Fixture/ORM/Person'])],
            [DbalConfiguration::createWithDefaults()],
            [ModulePackageList::DBAL_PACKAGE],
        );
    }

    private function bootstrapPersonRegistrationOnARegistryOfTwoManagers(): FlowTestSupport
    {
        $primaryConnection = $this->getConnection();
        $reportingConnection = $this->connectionForTenantB()->createContext()->getDbalConnection();
        $this->setupUserTable($primaryConnection);
        $this->setupUserTable($reportingConnection);
        $registry = new class (
            ['primary' => $primaryConnection, 'reporting' => $reportingConnection, 'archive_database' => (new DbalConnectionFactory(self::databaseDsn()))->createContext()->getDbalConnection()],
            [
                'primary' => $this->getORMConnectionFactory([__DIR__ . '/../Fixture/ORM/Person'], $primaryConnection)->getRegistry()->getManager(),
                'reporting' => $this->getORMConnectionFactory([__DIR__ . '/../Fixture/ORM/Person'], $reportingConnection)->getRegistry()->getManager(),
            ],
        ) implements ManagerRegistry {
            /**
             * @param array<string, Connection> $connections
             * @param array<string, ObjectManager> $managers
             */
            public function __construct(private array $connections, private array $managers)
            {
            }

            public function getDefaultConnectionName(): string
            {
                return 'primary';
            }

            public function getConnection($name = null): object
            {
                return $this->connections[$name ?? 'primary'];
            }

            public function getConnections(): array
            {
                return $this->connections;
            }

            public function getConnectionNames(): array
            {
                return array_combine(array_keys($this->connections), array_keys($this->connections));
            }

            public function getDefaultManagerName(): string
            {
                return 'primary';
            }

            public function getManager($name = null): ObjectManager
            {
                return $this->managers[$name ?? 'primary'];
            }

            public function getManagers(): array
            {
                return $this->managers;
            }

            public function resetManager($name = null): ObjectManager
            {
                return $this->getManager($name);
            }

            public function getManagerNames(): array
            {
                return array_combine(array_keys($this->managers), array_keys($this->managers));
            }

            public function getRepository($persistentObject, $persistentManagerName = null): ObjectRepository
            {
                return $this->getManager($persistentManagerName)->getRepository($persistentObject);
            }

            public function getManagerForClass($class): ?ObjectManager
            {
                return $this->getManager();
            }
        };
        $registration = new class () {
            #[CommandHandler('person.registerEntityInReporting')]
            public function registerEntityInReporting(array $command, #[CurrentConnectionObjectManager('reporting')] ObjectManager $objectManager): void
            {
                $objectManager->persist(Person::register(new RegisterPerson($command['personId'], $command['name']), false));
                $objectManager->flush();
            }

            #[CommandHandler('person.registerEntityInArchive')]
            public function registerEntityInArchive(array $command, #[CurrentConnectionObjectManager('archive')] ObjectManager $objectManager): void
            {
            }
        };

        return $this->bootstrapFlowTesting(
            [$registration::class, PersonsStoredThroughObjectManager::class, PersonsOfReportingStoredThroughObjectManager::class],
            [
                $registration,
                DbalConnectionFactory::class => DbalConnection::createForManagerRegistry($registry, 'primary'),
                'reporting' => DbalConnection::createForManagerRegistry($registry, 'reporting'),
                'archive' => DbalConnection::createForManagerRegistry($registry, 'archive_database'),
            ],
            [DbalConfiguration::createWithDefaults()],
            [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    private function bootstrapPersonRegistration(): FlowTestSupport
    {
        $this->setupUserTable();
        $this->setupUserTable($this->connectionForTenantB()->createContext()->getDbalConnection());
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
            [$registration::class, PersonsStoredThroughObjectManager::class, PersonsOfReportingStoredThroughObjectManager::class],
            [
                $registration,
                DbalConnectionFactory::class => ManagerRegistryProvider::fromDsnAndConfig(self::databaseDsn(), [__DIR__ . '/../Fixture/ORM/Person']),
                'reporting' => $this->connectionForTenantBWithManagerRegistry([__DIR__ . '/../Fixture/ORM/Person']),
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

interface PersonsOfReportingStoredThroughObjectManager
{
    #[DbalQuery('SELECT name FROM persons ORDER BY person_id', fetchMode: FetchMode::FIRST_COLUMN, connectionReferenceName: 'reporting')]
    public function names(): array;
}
