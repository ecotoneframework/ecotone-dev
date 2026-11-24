<?php

declare(strict_types=1);

namespace Test;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Dbal\Attribute\CurrentConnection;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\TransactionReconnectProtectionMiddleware;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Symfony\SymfonyConnectionReference;
use Ecotone\Messaging\Config\ConfigurationException;
use Fiber;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * licence Apache-2.0
 * @internal
 */
final class SymfonyConnectionCopiesTest extends TestCase
{
    public function test_executions_on_a_symfony_dbal_connection_keep_their_own_transaction(): void
    {
        if (str_starts_with((string) getenv('DATABASE_DSN'), 'sqlite')) {
            $this->markTestSkipped('SQLite locks the database for one writing connection at a time');
        }

        $ecotone = $this->bootstrapParcelsOn(SymfonyConnectionReference::defaultConnection('default'));

        $suspended = new Fiber(function () use ($ecotone): void {
            try {
                $ecotone->sendCommandWithRouting('parcel.register', ['parcelId' => 1, 'suspend' => true, 'fail' => true]);
            } catch (RuntimeException) {
            }
        });
        $suspended->start();
        $ecotone->sendCommandWithRouting('parcel.register', ['parcelId' => 2]);
        $suspended->resume();

        $this->assertSame([2], $ecotone->sendQueryWithRouting('parcel.registered'));
    }

    public function test_connection_pool_size_cannot_be_set_for_a_manager_registry_connection(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'Connection pool size cannot be set for `default`, because it is taken from a Doctrine ManagerRegistry: its EntityManager is bound to the one connection Symfony built, so Ecotone opens no further connections for it. '
            . "Register the connection without the registry to have a connection per concurrent execution: SymfonyConnectionReference::defaultConnection('default')->withConnectionPoolSize(20), or remove withConnectionPoolSize()."
        );

        SymfonyConnectionReference::defaultManagerRegistry('default')->withConnectionPoolSize(20);
    }

    public function test_connection_pool_size_below_one_is_refused(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Connection pool size for `default` is 0, but a pool needs at least one connection. Pass a positive number to withConnectionPoolSize(), or leave it out for the default of 10.');

        SymfonyConnectionReference::defaultConnection('default')->withConnectionPoolSize(0);
    }

    public function test_negative_connection_pool_wait_is_refused(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Connection pool wait for `default` is -1 seconds, but a wait cannot be negative. Pass 0 to withConnectionPoolWaitSeconds() to fail at once when every connection is in use, a number of seconds to wait for one, or leave it out for the default of 5 seconds.');

        SymfonyConnectionReference::defaultConnection('default')->withConnectionPoolWaitSeconds(-1);
    }

    public function test_connection_pool_wait_cannot_be_set_for_a_manager_registry_connection(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'Connection pool wait cannot be set for `default`, because it is taken from a Doctrine ManagerRegistry: its EntityManager is bound to the one connection Symfony built, so there is no pool to wait on. '
            . "Register the connection without the registry to have a connection per concurrent execution: SymfonyConnectionReference::defaultConnection('default')->withConnectionPoolWaitSeconds(10), or remove withConnectionPoolWaitSeconds()."
        );

        SymfonyConnectionReference::defaultManagerRegistry('default')->withConnectionPoolWaitSeconds(10);
    }


    public function test_create_for_connection_refuses_a_negative_pool_wait(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Connection pool wait for `default` is -1 seconds, but a wait cannot be negative. Pass 0 to withConnectionPoolWaitSeconds() to fail at once when every connection is in use, a number of seconds to wait for one, or leave it out for the default of 5 seconds.');

        SymfonyConnectionReference::createForConnection('default', connectionPoolWaitSeconds: -1);
    }

    private function bootstrapParcelsOn(SymfonyConnectionReference $connectionReference): FlowTestSupport
    {
        $connection = DriverManager::getConnection(
            (new DsnParser(['pgsql' => 'pdo_pgsql', 'mysql' => 'pdo_mysql', 'sqlite' => 'pdo_sqlite']))->parse(getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@localhost:5432/ecotone'),
            (new Configuration())->setMiddlewares([new TransactionReconnectProtectionMiddleware()]),
        );
        $connection->executeStatement('DROP TABLE IF EXISTS symfony_parcels');
        $connection->executeStatement('CREATE TABLE symfony_parcels (parcel_id INTEGER PRIMARY KEY)');
        $doctrine = new class ($connection) {
            public function __construct(private Connection $connection)
            {
            }

            public function getConnection(string $name): Connection
            {
                return $this->connection;
            }
        };
        $parcels = new class () {
            #[CommandHandler('parcel.register')]
            public function register(array $command, #[CurrentConnection] Connection $connection): void
            {
                $connection->insert('symfony_parcels', ['parcel_id' => $command['parcelId']]);

                if ($command['suspend'] ?? false) {
                    Fiber::suspend();
                }
                if ($command['fail'] ?? false) {
                    throw new RuntimeException('Parcel was refused');
                }
            }

            #[QueryHandler('parcel.registered')]
            public function registered(#[CurrentConnection] Connection $connection): array
            {
                return array_map('intval', $connection->fetchFirstColumn('SELECT parcel_id FROM symfony_parcels ORDER BY parcel_id'));
            }
        };

        return EcotoneLite::bootstrapFlowTesting(
            [$parcels::class],
            [$parcels, 'doctrine' => $doctrine],
            extensionObjects: [$connectionReference, DbalConfiguration::createWithDefaults()],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ModulePackageList::SYMFONY_PACKAGE],
        );
    }
}
