<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Doctrine\DBAL\Connection;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\QueryHandler;
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
            . 'Register it as a service under that name: new Ecotone\\Api\\Dbal\\DbalConnectionFactory($dsn), '
            . 'or use #[CurrentConnection] without a name for the default connection `Ecotone\\Api\\Dbal\\DbalConnectionFactory`.'
        );

        EcotoneLite::bootstrap(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(self::databaseDsn())],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            pathToRootCatalog: __DIR__ . '/../../',
            namespaces: [],
            environment: 'prod',
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
