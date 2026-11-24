<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Doctrine\DBAL\Connection;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\ConsoleCommand;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Dbal\Attribute\CurrentConnection;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ConfigurationException;
use Fiber;
use RuntimeException;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class CurrentConnectionTest extends DbalMessagingTestCase
{
    public function test_handler_stores_through_the_connection_ecotone_opened_from_the_dsn(): void
    {
        $ecotone = $this->bootstrapPersonRegistration();

        $ecotone->sendCommandWithRouting('person.register', ['personId' => 1, 'name' => 'accepted']);

        $this->assertSame(['accepted'], $ecotone->sendQueryWithRouting('person.names'));
    }

    public function test_what_handler_stored_through_the_injected_connection_is_rolled_back_with_the_message(): void
    {
        $ecotone = $this->bootstrapPersonRegistration();

        try {
            $ecotone->sendCommandWithRouting('person.register', ['personId' => 1, 'name' => 'refused', 'fail' => true]);
            $this->fail();
        } catch (RuntimeException) {
        }

        $this->assertSame([], $ecotone->sendQueryWithRouting('person.names'));
    }

    public function test_interleaved_executions_are_each_injected_with_their_own_connection(): void
    {
        $this->skipWhenTwoConnectionsCannotWriteAtOnce();

        $ecotone = $this->bootstrapPersonRegistration();

        $suspended = new Fiber(function () use ($ecotone): void {
            try {
                $ecotone->sendCommandWithRouting('person.register', ['personId' => 1, 'name' => 'refused', 'suspend' => true, 'fail' => true]);
            } catch (RuntimeException) {
            }
        });
        $suspended->start();
        $ecotone->sendCommandWithRouting('person.register', ['personId' => 2, 'name' => 'accepted']);
        $suspended->resume();

        $this->assertSame(['accepted'], $ecotone->sendQueryWithRouting('person.names'));
    }

    public function test_connection_registered_under_another_name_is_injected_by_that_name(): void
    {
        $ecotone = $this->bootstrapPersonRegistration();

        $ecotone->sendCommandWithRouting('person.registerInReporting', ['personId' => 1, 'name' => 'reported']);

        $this->assertSame(['reported'], $ecotone->sendQueryWithRouting('person.names'));
    }

    public function test_connection_taken_without_a_name_is_the_one_dbal_configuration_opens_transactions_on(): void
    {
        $ordersConnection = $this->connectionForTenantB()->createContext()->getDbalConnection();
        $this->setupUserTable();
        $this->setupUserTable($ordersConnection);
        $handler = new class () {
            #[CommandHandler('person.register')]
            public function register(array $command, #[CurrentConnection] Connection $connection): void
            {
                $connection->insert('persons', ['person_id' => $command['personId'], 'name' => $command['name']]);
                if ($command['fail'] ?? false) {
                    throw new RuntimeException('Registration was refused');
                }
            }
        };
        $ecotone = $this->bootstrapFlowTesting(
            [$handler::class],
            [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(self::databaseDsn()), 'orders' => $this->connectionForTenantB()],
            [DbalConfiguration::createWithDefaults()->withDefaultConnectionReferenceNames(['orders'])->withDeduplication(false)],
            [ModulePackageList::DBAL_PACKAGE],
        );

        $ecotone->sendCommandWithRouting('person.register', ['personId' => 1, 'name' => 'accepted']);
        try {
            $ecotone->sendCommandWithRouting('person.register', ['personId' => 2, 'name' => 'refused', 'fail' => true]);
            $this->fail();
        } catch (RuntimeException) {
        }

        $this->assertSame(['accepted'], $ordersConnection->fetchFirstColumn('SELECT name FROM persons ORDER BY person_id'));
        $this->assertSame([], $this->getConnection()->fetchFirstColumn('SELECT name FROM persons'));
    }

    public function test_connection_taken_without_a_name_is_refused_when_dbal_configuration_names_several_connections(): void
    {
        $handler = new class () {
            #[CommandHandler('person.register')]
            public function register(array $command, #[CurrentConnection] Connection $connection): void
            {
            }
        };

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            '#[CurrentConnection] without a connection name on ' . $handler::class . '::register($connection) cannot tell which connection is meant: DbalConfiguration names several (`orders`, `billing`). '
            . "Name the connection: #[CurrentConnection('orders')]."
        );

        $this->bootstrapFlowTesting(
            [$handler::class],
            [$handler, 'orders' => new DbalConnectionFactory(self::databaseDsn()), 'billing' => new DbalConnectionFactory(self::databaseDsn())],
            [DbalConfiguration::createWithDefaults()->withDefaultConnectionReferenceNames(['orders', 'billing'])->withTransactionOnCommandBus(false)->withTransactionOnAsynchronousEndpoints(false)->withDeduplication(false)->withDeadLetter(false)],
            [ModulePackageList::DBAL_PACKAGE],
        );
    }

    public function test_current_connection_on_a_parameter_that_cannot_hold_a_connection_is_refused(): void
    {
        $handler = new class () {
            #[CommandHandler('person.register')]
            public function register(array $command, #[CurrentConnection] string $connection): void
            {
            }
        };

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            '#[CurrentConnection] on ' . $handler::class . '::register($connection) injects the Doctrine\\DBAL\\Connection Ecotone opened for the message being handled, but the parameter is typed `string`. '
            . 'Type the parameter as Doctrine\\DBAL\\Connection, or remove #[CurrentConnection] from it.'
        );

        $this->bootstrapFlowTesting(
            [$handler::class],
            [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(self::databaseDsn())],
            [DbalConfiguration::createWithDefaults()],
            [ModulePackageList::DBAL_PACKAGE],
        );
    }

    public function test_current_connection_naming_a_connection_that_is_not_registered_is_refused(): void
    {
        $handler = new class () {
            #[CommandHandler('person.register')]
            public function register(array $command, #[CurrentConnection('reporting')] Connection $connection): void
            {
            }
        };

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            "#[CurrentConnection('reporting')] on " . $handler::class . '::register($connection) needs a connection registered as `reporting`, and there is none. '
            . 'Register it under that name: a service new Ecotone\\Api\\Dbal\\DbalConnectionFactory($dsn), SymfonyConnectionReference::createForConnection($connectionName, $referenceName), LaravelConnectionReference::create($connectionName, $referenceName) or TempestConnectionReference::create($configTag, $referenceName), '
            . 'or use #[CurrentConnection] without a name for the connection messages are handled on, `Ecotone\\Api\\Dbal\\DbalConnectionFactory`.'
        );

        EcotoneLite::bootstrap(
            containerOrAvailableServices: [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(self::databaseDsn())],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            pathToRootCatalog: __DIR__ . '/../../',
            namespaces: [],
            environment: 'prod',
        );
    }

    public function test_connection_injected_into_a_handler_as_a_service_is_refused(): void
    {
        $handler = new class () {
            #[CommandHandler('person.register')]
            public function register(array $command, #[Reference] Connection $connection): void
            {
            }
        };

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            $handler::class . '::register($connection) takes a Doctrine\\DBAL\\Connection without #[CurrentConnection]. '
            . 'A connection injected as a service is one fixed connection, and the message being handled does not have to run on it: Ecotone gives every execution running concurrently (a coroutine or a fiber) its own connection, so what the handler stores through the injected one would be outside the transaction of the message. '
            . 'Mark the parameter with #[CurrentConnection] (Ecotone\\Api\\Dbal\\Attribute\\CurrentConnection) to be given the connection the message is handled on, or with #[CurrentConnection(\'name\')] for a connection registered under another name. A connection Ecotone does not manage is taken through the constructor of the class instead.'
        );

        $this->bootstrapFlowTesting(
            [$handler::class],
            [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(self::databaseDsn())],
            [DbalConfiguration::createWithDefaults()],
            [ModulePackageList::DBAL_PACKAGE],
        );
    }

    public function test_connection_taken_by_an_event_handler_as_a_service_is_refused(): void
    {
        $handler = new class () {
            #[EventHandler('person.registered')]
            public function notify(array $event, Connection $connection): void
            {
            }
        };

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($handler::class . '::notify($connection) takes a Doctrine\\DBAL\\Connection without #[CurrentConnection].');

        $this->bootstrapFlowTesting(
            [$handler::class],
            [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(self::databaseDsn())],
            [DbalConfiguration::createWithDefaults()],
            [ModulePackageList::DBAL_PACKAGE],
        );
    }

    public function test_connection_taken_by_a_console_command_as_a_service_is_refused(): void
    {
        $command = new class () {
            #[ConsoleCommand('person:register')]
            public function register(#[Reference] Connection $connection): void
            {
            }
        };

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($command::class . '::register($connection) takes a Doctrine\\DBAL\\Connection without #[CurrentConnection].');

        $this->bootstrapFlowTesting(
            [$command::class],
            [$command, DbalConnectionFactory::class => new DbalConnectionFactory(self::databaseDsn())],
            [DbalConfiguration::createWithDefaults()],
            [ModulePackageList::DBAL_PACKAGE],
        );
    }

    private function bootstrapPersonRegistration(): FlowTestSupport
    {
        $this->setupUserTable();
        $registration = new class () {
            #[CommandHandler('person.register')]
            public function register(array $command, #[CurrentConnection] Connection $connection): void
            {
                $connection->insert('persons', ['person_id' => $command['personId'], 'name' => $command['name']]);

                if ($command['suspend'] ?? false) {
                    Fiber::suspend();
                }
                if ($command['fail'] ?? false) {
                    throw new RuntimeException('Registration was refused');
                }
            }

            #[CommandHandler('person.registerInReporting')]
            public function registerInReporting(array $command, #[CurrentConnection('reporting')] Connection $connection): void
            {
                $connection->insert('persons', ['person_id' => $command['personId'], 'name' => $command['name']]);
            }

            #[QueryHandler('person.names')]
            public function names(#[CurrentConnection] Connection $connection): array
            {
                return $connection->fetchFirstColumn('SELECT name FROM persons ORDER BY person_id');
            }
        };

        return $this->bootstrapFlowTesting(
            [$registration::class],
            [
                $registration,
                DbalConnectionFactory::class => new DbalConnectionFactory(self::databaseDsn()),
                'reporting' => new DbalConnectionFactory(self::databaseDsn()),
            ],
            [DbalConfiguration::createWithDefaults()],
            [ModulePackageList::DBAL_PACKAGE],
        );
    }
}
