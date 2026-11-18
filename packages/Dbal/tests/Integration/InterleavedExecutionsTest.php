<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Dbal\Attribute\DbalQuery;
use Ecotone\Api\Dbal\Attribute\DbalWrite;
use Ecotone\Api\Dbal\ConnectionPoolExhausted;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\FetchMode;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Enqueue\ConnectionFactory;
use Ecotone\Messaging\Config\ConfigurationException;
use Fiber;
use RuntimeException;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class InterleavedExecutionsTest extends DbalMessagingTestCase
{
    public function test_a_suspended_execution_that_fails_does_not_roll_back_an_interleaved_one(): void
    {
        $ecotone = $this->bootstrapPersonRegistration();

        $suspended = new Fiber(function () use ($ecotone): void {
            try {
                $ecotone->sendCommandWithRouting('person.register', ['personId' => 1, 'name' => 'refused', 'suspendAndFail' => true]);
            } catch (RuntimeException) {
            }
        });
        $suspended->start();
        $ecotone->sendCommandWithRouting('person.register', ['personId' => 2, 'name' => 'accepted', 'suspendAndFail' => false]);
        $suspended->resume();

        $this->assertSame(['accepted'], $ecotone->getGateway(PersonRegister::class)->names());
    }

    public function test_an_interleaved_execution_that_fails_does_not_roll_back_the_suspended_one(): void
    {
        $ecotone = $this->bootstrapPersonRegistration();

        $suspended = new Fiber(fn () => $ecotone->sendCommandWithRouting('person.register', ['personId' => 1, 'name' => 'accepted', 'suspend' => true]));
        $suspended->start();
        try {
            $ecotone->sendCommandWithRouting('person.register', ['personId' => 2, 'name' => 'refused', 'fail' => true]);
        } catch (RuntimeException) {
        }
        $suspended->resume();

        $this->assertSame(['accepted'], $ecotone->getGateway(PersonRegister::class)->names());
    }

    public function test_message_sent_to_a_database_channel_is_committed_with_its_own_execution(): void
    {
        $ecotone = $this->bootstrapPersonRegistration();

        $suspended = new Fiber(function () use ($ecotone): void {
            try {
                $ecotone->sendCommandWithRouting('person.register', ['personId' => 1, 'name' => 'refused', 'suspendAndFail' => true]);
            } catch (RuntimeException) {
            }
        });
        $suspended->start();
        $ecotone->sendCommandWithRouting('person.register', ['personId' => 2, 'name' => 'accepted']);
        $suspended->resume();
        $ecotone->run('welcomes');

        $this->assertSame(['accepted'], $ecotone->sendQueryWithRouting('person.welcomed'));
    }

    public function test_connection_of_a_finished_execution_is_reused_by_the_next_one(): void
    {
        if ($this->isUsingSqlite()) {
            $this->markTestSkipped('SQLite has no server side connections to count');
        }

        $ecotone = $this->bootstrapPersonRegistration();
        $ecotone->getGateway(PersonRegister::class)->names();
        $connectionsBefore = $this->openConnections();

        for ($personId = 1; $personId <= 5; $personId++) {
            $execution = new Fiber(fn () => $ecotone->sendCommandWithRouting('person.register', ['personId' => $personId, 'name' => "person-{$personId}", 'suspend' => true]));
            $execution->start();
            $execution->resume();
            $execution = null;
        }

        $this->assertCount(5, $ecotone->getGateway(PersonRegister::class)->names());
        $this->assertSame($connectionsBefore + 1, $this->openConnections());
    }

    public function test_transaction_on_a_connection_the_application_shares_between_executions_is_refused(): void
    {
        $ecotone = $this->bootstrapPersonRegistration($this->getConnectionFactory(isRegistry: true));

        $suspended = new Fiber(fn () => $ecotone->sendCommandWithRouting('person.register', ['personId' => 1, 'name' => 'accepted', 'suspend' => true]));
        $suspended->start();
        try {
            $ecotone->sendCommandWithRouting('person.register', ['personId' => 2, 'name' => 'interleaved']);
            $this->fail('The interleaved execution joined the transaction of the suspended one');
        } catch (ConfigurationException $exception) {
            $this->assertSame(
                'Database connection `' . DbalConnectionFactory::class . '` is already inside a transaction opened by another execution running concurrently in this process (a coroutine or a fiber). '
                . 'Two executions on one connection share one transaction: a rollback of either one discards what the other stored. '
                . 'Ecotone gives every concurrent execution its own connection when it creates the connections itself, so register the connection from a DSN: new Ecotone\\Api\\Dbal\\DbalConnectionFactory($dsn). '
                . 'A connection built by the application or its framework (Doctrine ManagerRegistry, Laravel database connection, an existing Doctrine\\DBAL\\Connection) is a single connection for the whole process, so with it handle one message at a time per process: in Swoole or OpenSwoole set `enable_coroutine` to false (the default of Laravel Octane). '
                . 'Or run the handler without a database transaction: #[WithoutDatabaseTransaction] (Ecotone\\Api\\Attribute\\WithoutDatabaseTransaction), or DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(false).',
                $exception->getMessage()
            );
        } finally {
            $suspended->resume();
        }

        $this->assertSame(['accepted'], $ecotone->getGateway(PersonRegister::class)->names());
    }

    public function test_execution_is_refused_when_every_connection_of_the_pool_is_held_by_another_execution(): void
    {
        $ecotone = $this->bootstrapPersonRegistration(new DbalConnectionFactory(['dsn' => self::databaseDsn(), 'connection_pool_size' => 2]));

        $suspended = new Fiber(fn () => $ecotone->sendCommandWithRouting('person.register', ['personId' => 1, 'name' => 'accepted', 'suspend' => true]));
        $suspended->start();
        try {
            (new Fiber(fn () => $ecotone->sendCommandWithRouting('person.register', ['personId' => 2, 'name' => 'interleaved'])))->start();
            $this->fail('The interleaved execution was given a connection above the pool size');
        } catch (ConnectionPoolExhausted $exception) {
            $this->assertSame(
                'All 2 database connections of this connection pool are held by executions running concurrently in this process (coroutines or fibers), and none was returned. '
                . "Raise the pool size where the connection is registered: new Ecotone\\Api\\Dbal\\DbalConnectionFactory(['dsn' => \$dsn, 'connection_pool_size' => 20]); every worker process may then open that many connections, so keep workers times pool size below what the database accepts. "
                . 'Or let fewer executions run at once: in Swoole or OpenSwoole lower `max_coroutine` or `worker_max_concurrency`.',
                $exception->getMessage()
            );
        } finally {
            $suspended->resume();
        }

        $this->assertSame(['accepted'], $ecotone->getGateway(PersonRegister::class)->names());
    }

    public function test_connection_pool_size_below_one_is_refused(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("Connection pool size is 0, but a pool needs at least one connection. Pass a positive `connection_pool_size`: new Ecotone\\Api\\Dbal\\DbalConnectionFactory(['dsn' => \$dsn, 'connection_pool_size' => 10]), or leave it out for the default of 10.");

        new DbalConnectionFactory(['dsn' => self::databaseDsn(), 'connection_pool_size' => 0]);
    }

    private function bootstrapPersonRegistration(?ConnectionFactory $connectionFactory = null): FlowTestSupport
    {
        $this->setupUserTable();
        $registration = new class () {
            private array $welcomed = [];

            #[CommandHandler('person.register')]
            public function register(array $command, PersonRegister $personRegister, EventBus $eventBus): void
            {
                $personRegister->register($command['personId'], $command['name']);
                $eventBus->publish(new PersonWasRegistered($command['name']));

                if (($command['suspend'] ?? false) || ($command['suspendAndFail'] ?? false)) {
                    Fiber::suspend();
                }
                if (($command['fail'] ?? false) || ($command['suspendAndFail'] ?? false)) {
                    throw new RuntimeException('Registration was refused');
                }
            }

            #[Asynchronous('welcomes')]
            #[EventHandler(endpointId: 'person.welcome')]
            public function welcome(PersonWasRegistered $event): void
            {
                $this->welcomed[] = $event->name;
            }

            #[QueryHandler('person.welcomed')]
            public function welcomed(): array
            {
                return $this->welcomed;
            }
        };

        return $this->bootstrapFlowTesting(
            [$registration::class, PersonRegister::class],
            [$registration, DbalConnectionFactory::class => $connectionFactory ?? new DbalConnectionFactory(self::databaseDsn())],
            [
                DbalConfiguration::createWithDefaults(),
                DbalBackedMessageChannelBuilder::create('welcomes')->withReceiveTimeout(1),
            ],
            [ModulePackageList::DBAL_PACKAGE],
        );
    }

    private function openConnections(): int
    {
        $connection = $this->getConnection();

        return (int) $connection->fetchOne(
            $connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform
                ? 'SELECT count(*) FROM pg_stat_activity WHERE datname = current_database()'
                : 'SELECT count(*) FROM information_schema.PROCESSLIST WHERE db = DATABASE()',
        );
    }
}

interface PersonRegister
{
    #[DbalWrite('INSERT INTO persons (person_id, name) VALUES (:personId, :name)')]
    public function register(int $personId, string $name): void;

    #[DbalQuery('SELECT name FROM persons ORDER BY person_id', fetchMode: FetchMode::FIRST_COLUMN)]
    public function names(): array;
}

final class PersonWasRegistered
{
    public function __construct(public string $name)
    {
    }
}
