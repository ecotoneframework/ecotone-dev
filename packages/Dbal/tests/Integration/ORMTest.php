<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Dbal\DbalConnection;
use Ecotone\Dbal\EcotoneManagerRegistryConnectionFactory;
use Ecotone\Dbal\ManagerRegistryEmulator;
use Ecotone\Dbal\MultiTenant\MultiTenantConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Modelling\AggregateNotFoundException;
use Enqueue\Dbal\DbalConnectionFactory;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\ORM\FailureMode\MultipleInternalCommandsService;
use Test\Ecotone\Dbal\Fixture\ORM\Person\Person;
use Test\Ecotone\Dbal\Fixture\ORM\Person\RegisterPerson;
use Test\Ecotone\Dbal\Fixture\ORM\PersonQueryHandler\PersonQueryService;
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

        $ecotone->sendCommand(new RegisterPerson(100, 'Johny'));

        self::assertEquals(
            'Johny',
            $ecotone->sendQueryWithRouting('person.getName', metadata: ['aggregate.id' => 100])
        );
    }

    public function configuration(): iterable
    {
        $connectionFactory = $this->getORMConnectionFactory([__DIR__ . '/../Fixture/ORM/Person']);
        $ORMPersonRepository = new ORMPersonRepository($connectionFactory->getRegistry());

        yield 'For standard Object Manager Connection' => [
            [DbalConnectionFactory::class => $connectionFactory, ORMPersonRepository::class => $ORMPersonRepository, RegisterPersonService::class => new RegisterPersonService()],
            ['Test\Ecotone\Dbal\Fixture\ORM\PersonRepository'],
            false,
        ];
        yield 'For Aggregate with Object Manager Connection' => [
            [DbalConnectionFactory::class => $connectionFactory],
            ['Test\Ecotone\Dbal\Fixture\ORM\Person'],
            true,
        ];
    }

    public function multiTenantConnectionConfiguration(): iterable
    {
        $tenantConnections = [
            'tenant_a_connection' => $this->connectionForTenantA(),
            'tenant_b_connection' => $this->connectionForTenantB(),
        ];

        yield 'For standard Object Manager Connection' => [
            array_merge(
                [
                    \Test\Ecotone\Dbal\Fixture\ORM\MultiTenant\ORMPersonRepository::class => new \Test\Ecotone\Dbal\Fixture\ORM\MultiTenant\ORMPersonRepository(),
                    \Test\Ecotone\Dbal\Fixture\ORM\MultiTenant\RegisterPersonService::class => new \Test\Ecotone\Dbal\Fixture\ORM\MultiTenant\RegisterPersonService(),
                ],
                $tenantConnections
            ),
            ['Test\Ecotone\Dbal\Fixture\ORM\MultiTenant'],
            false,
            MultiTenantConfiguration::create(
                'tenant',
                [
                    'tenant_a' => 'tenant_a_connection',
                    'tenant_b' => 'tenant_b_connection',
                ]
            ),
        ];
        yield 'For Aggregate with Object Manager Connection' => [
            $tenantConnections,
            ['Test\Ecotone\Dbal\Fixture\ORM\Person'],
            true,
            MultiTenantConfiguration::create(
                'tenant',
                [
                    'tenant_a' => 'tenant_a_connection',
                    'tenant_b' => 'tenant_b_connection',
                ]
            ),
        ];
    }

    /**
     * @dataProvider multiTenantConnectionConfiguration
     */
    public function test_flushing_object_manager_on_command_bus_with_tenant(array $services, array $namespaces, bool $enableDoctrineORMAggregates, MultiTenantConfiguration $multiTenantConfiguration): void
    {
        $this->setupUserTable($this->connectionForTenantA()->getConnection());
        $this->setupUserTable($this->connectionForTenantB()->getConnection());

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: array_merge([
                SaveMultipleEntitiesHandler::class => new SaveMultipleEntitiesHandler(),
                PersonQueryService::class => new PersonQueryService(),
            ], $services),
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects(array_merge([
                    DbalConfiguration::createWithDefaults()
                        ->withDoctrineORMRepositories($enableDoctrineORMAggregates),
                ], [$multiTenantConfiguration]))
                ->withNamespaces(array_merge($namespaces, [
                    /** This registers second Person with id +1 */
                    'Test\Ecotone\Dbal\Fixture\ORM\SynchronousEventHandler',
                    'Test\Ecotone\Dbal\Fixture\ORM\PersonQueryHandler',
                ])),
            pathToRootCatalog: __DIR__ . '/../../',
            addInMemoryStateStoredRepository: false,
        );

        /** For Tenant A */
        $ecotone->sendCommand(new RegisterPerson(100, 'Johnny'), metadata: ['tenant' => 'tenant_a']);
        $ecotone->sendCommandWithRoutingKey(Person::RENAME_COMMAND, 'Paul', metadata: ['aggregate.id' => 100, 'tenant' => 'tenant_a']);

        self::assertEquals(
            'Paul',
            $ecotone->sendQueryWithRouting('person.byById', 100, metadata: ['aggregate.id' => 100, 'tenant' => 'tenant_a'])->getName()
        );
        self::assertEquals(
            'Paul2',
            $ecotone->sendQueryWithRouting('person.byById', 101, metadata: ['aggregate.id' => 101, 'tenant' => 'tenant_a'])->getName()
        );
        self::assertEquals(
            [100, 101],
            $ecotone->sendQueryWithRouting('person.getAllIds', metadata: ['tenant' => 'tenant_a'])
        );
        self::assertEquals(
            [],
            $ecotone->sendQueryWithRouting('person.getAllIds', metadata: ['tenant' => 'tenant_b'])
        );

        /** For Tenant B */
        $ecotone->sendCommand(new RegisterPerson(1, 'Johnny'), metadata: ['tenant' => 'tenant_b']);
        $ecotone->sendCommandWithRoutingKey(Person::RENAME_COMMAND, 'Paul', metadata: ['aggregate.id' => 1, 'tenant' => 'tenant_b']);

        self::assertEquals(
            'Paul',
            $ecotone->sendQueryWithRouting('person.byById', 1, metadata: ['aggregate.id' => 1, 'tenant' => 'tenant_b'])->getName()
        );
        self::assertEquals(
            'Paul2',
            $ecotone->sendQueryWithRouting('person.byById', 2, metadata: ['aggregate.id' => 2, 'tenant' => 'tenant_b'])->getName()
        );
        self::assertEquals(
            [1, 2],
            $ecotone->sendQueryWithRouting('person.getAllIds', metadata: ['tenant' => 'tenant_b'])
        );
        self::assertEquals(
            [100, 101],
            $ecotone->sendQueryWithRouting('person.getAllIds', metadata: ['tenant' => 'tenant_a'])
        );
    }

    /**
     * @dataProvider configuration
     */
    public function test_flushing_object_manager_on_command_bus(array $services, array $namespaces, bool $enableDoctrineORMAggregates): void
    {
        $this->setupUserTable();

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: array_merge([
                SaveMultipleEntitiesHandler::class => new SaveMultipleEntitiesHandler(),
                PersonQueryService::class => new PersonQueryService(),
            ], $services),
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()
                        ->withDoctrineORMRepositories($enableDoctrineORMAggregates),
                ])
                ->withNamespaces(array_merge($namespaces, [
                    /** This registers second Person with id +1 */
                    'Test\Ecotone\Dbal\Fixture\ORM\SynchronousEventHandler',
                    'Test\Ecotone\Dbal\Fixture\ORM\PersonQueryHandler',
                ])),
            pathToRootCatalog: __DIR__ . '/../../',
            addInMemoryStateStoredRepository: false,
        );

        $ecotone->sendCommand(new RegisterPerson(100, 'Johnny'));
        $ecotone->sendCommandWithRoutingKey(Person::RENAME_COMMAND, 'Paul', metadata: ['aggregate.id' => 100]);

        self::assertEquals(
            'Paul',
            $ecotone->sendQueryWithRouting('person.byById', 100, metadata: ['aggregate.id' => 100])->getName()
        );
        self::assertEquals(
            'Paul2',
            $ecotone->sendQueryWithRouting('person.byById', 101, metadata: ['aggregate.id' => 101])->getName()
        );
        self::assertEquals(
            [100, 101],
            $ecotone->sendQueryWithRouting('person.getAllIds')
        );
    }

    /**
     * @dataProvider configuration
     */
    public function test_disabling_flushing_object_manager_on_command_bus(array $services, array $namespaces, bool $enableDoctrineORMAggregates)
    {
        $this->setupUserTable();
        /** @var EcotoneManagerRegistryConnectionFactory $connectionFactory */
        $connectionFactory = $services[DbalConnectionFactory::class];
        $entityManager = $connectionFactory->getRegistry()->getManager();

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: $services,
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withNamespaces($namespaces)
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()
                        ->withDoctrineORMRepositories($enableDoctrineORMAggregates)
                        ->withClearAndFlushObjectManagerOnCommandBus(false),
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            addInMemoryStateStoredRepository: false
        );

        $ecotone->sendCommand(new RegisterPerson(100, 'Johnny'));
        $entityManager->clear();

        $this->expectException(AggregateNotFoundException::class);

        $ecotone->sendQueryWithRouting('person.byById', 100, metadata: ['aggregate.id' => 100]);
    }

    /**
     * @dataProvider configuration
     */
    public function test_object_manager_reconnects_on_command_bus(array $services, array $namespaces, bool $enableDoctrineORMAggregates)
    {
        $this->setupUserTable();
        /** @var EcotoneManagerRegistryConnectionFactory $connectionFactory */
        $connectionFactory = $services[DbalConnectionFactory::class];
        $entityManager = $connectionFactory->getRegistry()->getManager();

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: $services,
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()
                        ->withDoctrineORMRepositories($enableDoctrineORMAggregates),
                ])
                ->withNamespaces($namespaces),
            pathToRootCatalog: __DIR__ . '/../../',
            addInMemoryStateStoredRepository: false
        );

        $entityManager->close();
        $ecotone->sendCommand(new RegisterPerson(100, 'Johnny'));

        $this->assertNotNull(
            $ecotone->sendQueryWithRouting('person.byById', 100, metadata: ['aggregate.id' => 100])
        );
    }

    public function test_throwing_exception_when_setting_up_doctrine_orm_using_non_orm_registry_based_connection()
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [Person::class, MultipleInternalCommandsService::class],
            [new MultipleInternalCommandsService(), DbalConnectionFactory::class => DbalConnection::create($this->getConnection())],
            ServiceConfiguration::createWithDefaults()
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()
                        ->withDoctrineORMRepositories(true),
                    DbalBackedMessageChannelBuilder::create('async'),
                ])
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DBAL_PACKAGE])),
            addInMemoryStateStoredRepository: false
        );

        $this->expectException(InvalidArgumentException::class);

        $ecotoneLite->sendCommandWithRoutingKey('multipleInternalCommands', [['personId' => 99, 'personName' => 'Johny', 'exception' => false]]);
        $ecotoneLite->run('async', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 2, failAtError: true));
    }

    private function bootstrapEcotone(): FlowTestSupport
    {
        $this->setupUserTable();

        return EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [DbalConnectionFactory::class => $this->getORMConnectionFactory([__DIR__.'/../Fixture/ORM/Person'])],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
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

    protected function connectionForTenantB(): EcotoneManagerRegistryConnectionFactory
    {
        $connectionTenantB = ManagerRegistryEmulator::fromDsnAndConfig(
            getenv('SECONDARY_DATABASE_DSN') ? getenv('SECONDARY_DATABASE_DSN') : 'mysql://ecotone:secret@localhost:3306/ecotone',
            [__DIR__ . '/../Fixture/ORM/Person']
        );
        return $connectionTenantB;
    }

    protected function connectionForTenantA(): EcotoneManagerRegistryConnectionFactory
    {
        $connectionTenantA = ManagerRegistryEmulator::fromDsnAndConfig(
            getenv('DATABASE_DSN') ? getenv('DATABASE_DSN') : 'pgsql://ecotone:secret@localhost:5432/ecotone',
            [__DIR__ . '/../Fixture/ORM/Person']
        );
        return $connectionTenantA;
    }
}
