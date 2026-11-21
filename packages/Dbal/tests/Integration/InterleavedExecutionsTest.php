<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\BusinessMethod;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Dbal\Attribute\DbalQuery;
use Ecotone\Api\Dbal\Attribute\DbalTransaction;
use Ecotone\Api\Dbal\Attribute\DbalWrite;
use Ecotone\Api\Dbal\ConnectionPoolExhausted;
use Ecotone\Api\Dbal\DbalConnection;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\FetchMode;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Lite\Test\FlowTestSupport;
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
        $this->skipWhenTwoConnectionsCannotWriteAtOnce();

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
        $this->skipWhenTwoConnectionsCannotWriteAtOnce();

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
        $this->skipWhenTwoConnectionsCannotWriteAtOnce();

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

    public function test_executions_on_a_manager_registry_built_from_a_dsn_keep_their_own_transaction(): void
    {
        $this->skipWhenTwoConnectionsCannotWriteAtOnce();

        $ecotone = $this->bootstrapPersonRegistration(DbalConnection::fromConnectionFactory(new DbalConnectionFactory(self::databaseDsn())));

        $suspended = new Fiber(function () use ($ecotone): void {
            try {
                $ecotone->sendCommandWithRouting('person.register', ['personId' => 1, 'name' => 'refused', 'suspendAndFail' => true]);
            } catch (RuntimeException) {
            }
        });
        $suspended->start();
        $ecotone->sendCommandWithRouting('person.register', ['personId' => 2, 'name' => 'accepted']);
        $suspended->resume();

        $this->assertSame(['accepted'], $ecotone->getGateway(PersonRegister::class)->names());
    }

    public function test_executions_on_an_application_connection_registered_with_copies_keep_their_own_transaction(): void
    {
        $this->skipWhenTwoConnectionsCannotWriteAtOnce();

        $ecotone = $this->bootstrapPersonRegistration(DbalConnection::createWithCopiesForConcurrentExecutions($this->getConnection()));

        $suspended = new Fiber(function () use ($ecotone): void {
            try {
                $ecotone->sendCommandWithRouting('person.register', ['personId' => 1, 'name' => 'refused', 'suspendAndFail' => true]);
            } catch (RuntimeException) {
            }
        });
        $suspended->start();
        $ecotone->sendCommandWithRouting('person.register', ['personId' => 2, 'name' => 'accepted']);
        $suspended->resume();

        $this->assertSame(['accepted'], $ecotone->getGateway(PersonRegister::class)->names());
    }

    public function test_message_handled_outside_of_concurrent_executions_uses_the_application_connection_itself(): void
    {
        $applicationConnection = $this->getConnection();
        $ecotone = $this->bootstrapPersonRegistration(DbalConnection::createWithCopiesForConcurrentExecutions($applicationConnection));

        $applicationConnection->beginTransaction();
        $ecotone->sendCommandWithRouting('person.register', ['personId' => 1, 'name' => 'inside the transaction of the application']);
        $namesBeforeRollback = $ecotone->getGateway(PersonRegister::class)->names();
        $applicationConnection->rollBack();

        $this->assertSame(['inside the transaction of the application'], $namesBeforeRollback);
        $this->assertSame([], $ecotone->getGateway(PersonRegister::class)->names());
    }

    public function test_copies_of_an_application_connection_are_limited_to_the_connection_pool_size(): void
    {
        $ecotone = $this->bootstrapPersonRegistration(DbalConnection::createWithCopiesForConcurrentExecutions($this->getConnection(), connectionPoolSize: 1));

        $suspended = new Fiber(fn () => $ecotone->sendCommandWithRouting('person.register', ['personId' => 1, 'name' => 'accepted', 'suspend' => true]));
        $suspended->start();
        try {
            (new Fiber(fn () => $ecotone->sendCommandWithRouting('person.register', ['personId' => 2, 'name' => 'interleaved'])))->start();
            $this->fail();
        } catch (ConnectionPoolExhausted) {
        } finally {
            $suspended->resume();
        }

        $this->assertSame(['accepted'], $ecotone->getGateway(PersonRegister::class)->names());
    }

    public function test_query_does_not_read_what_a_suspended_execution_has_not_committed(): void
    {
        $ecotone = $this->bootstrapPersonRegistration();

        $suspended = new Fiber(fn () => $ecotone->sendCommandWithRouting('person.register', ['personId' => 1, 'name' => 'accepted', 'suspend' => true]));
        $suspended->start();
        $namesReadWhileSuspended = $ecotone->sendQueryWithRouting('person.names');
        $suspended->resume();

        $this->assertSame([], $namesReadWhileSuspended);
        $this->assertSame(['accepted'], $ecotone->sendQueryWithRouting('person.names'));
    }

    public function test_commands_routed_by_their_class_keep_their_own_transaction(): void
    {
        $this->skipWhenTwoConnectionsCannotWriteAtOnce();

        $ecotone = $this->bootstrapPersonRegistration();

        $suspended = new Fiber(function () use ($ecotone): void {
            try {
                $ecotone->sendCommand(new RegisterPersonInExecution(['personId' => 1, 'name' => 'refused', 'suspendAndFail' => true]));
            } catch (RuntimeException) {
            }
        });
        $suspended->start();
        $ecotone->sendCommand(new RegisterPersonInExecution(['personId' => 2, 'name' => 'accepted']));
        $suspended->resume();

        $this->assertSame(['accepted'], $ecotone->getGateway(PersonRegister::class)->names());
    }

    public function test_messages_sent_through_a_gateway_keep_their_own_transaction(): void
    {
        $this->skipWhenTwoConnectionsCannotWriteAtOnce();

        $ecotone = $this->bootstrapPersonRegistration();

        $suspended = new Fiber(function () use ($ecotone): void {
            try {
                $ecotone->getGateway(PersonRegistrationGateway::class)->register(['personId' => 1, 'name' => 'refused', 'suspendAndFail' => true]);
            } catch (RuntimeException) {
            }
        });
        $suspended->start();
        $ecotone->getGateway(PersonRegistrationGateway::class)->register(['personId' => 2, 'name' => 'accepted']);
        $suspended->resume();

        $this->assertSame(['accepted'], $ecotone->getGateway(PersonRegister::class)->names());
    }

    public function test_messages_handled_by_interleaved_consumers_keep_their_own_transaction(): void
    {
        $this->skipWhenTwoConnectionsCannotWriteAtOnce();

        $ecotone = $this->bootstrapPersonRegistration();
        $ecotone->sendCommandWithRouting('person.registerLater', ['personId' => 1, 'name' => 'refused', 'suspendAndFail' => true]);
        $ecotone->sendCommandWithRouting('person.registerLater', ['personId' => 2, 'name' => 'accepted']);

        $suspended = new Fiber(function () use ($ecotone): void {
            try {
                $ecotone->run('registrations');
            } catch (RuntimeException) {
            }
        });
        $suspended->start();
        $ecotone->run('registrations');
        $suspended->resume();

        $this->assertSame(['accepted'], $ecotone->getGateway(PersonRegister::class)->names());
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
        $ecotone = $this->bootstrapPersonRegistration(DbalConnection::create($this->getConnection()));

        $suspended = new Fiber(fn () => $ecotone->sendCommandWithRouting('person.register', ['personId' => 1, 'name' => 'accepted', 'suspend' => true]));
        $suspended->start();
        try {
            $ecotone->sendCommandWithRouting('person.register', ['personId' => 2, 'name' => 'interleaved']);
            $this->fail();
        } catch (ConfigurationException $exception) {
            $this->assertSame(
                'Database connection `' . DbalConnectionFactory::class . '` is already inside a transaction opened by another execution of this process (a coroutine or a fiber running concurrently, or one the handler holding the transaction started). '
                . 'Two executions on one connection share one transaction: a rollback of either one discards what the other stored. '
                . 'Ecotone gives every concurrent execution its own connection when it can open one itself: register the connection from a DSN (new Ecotone\\Api\\Dbal\\DbalConnectionFactory($dsn)), register a Symfony DBAL connection with SymfonyConnectionReference::defaultConnection() or ::createForConnection(), or hand an existing connection over with Ecotone\\Api\\Dbal\\DbalConnection::createWithCopiesForConcurrentExecutions($connection); the last two work on copies of that connection. '
                . 'A Doctrine ManagerRegistry and a Laravel or Tempest database connection stay a single connection for the whole process, so with them handle one message at a time per process: in Swoole or OpenSwoole set `enable_coroutine` to false (the default of Laravel Octane), and do not begin a transaction from a coroutine or fiber the handler starts. '
                . 'Running without a database transaction (#[WithoutDatabaseTransaction], or DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(false), ->withTransactionOnAsynchronousEndpoints(false) or ->withTransactionOnConsoleCommands(false)) removes this refusal but not the cause: the executions still share the connection, their writes are no longer atomic, and MySQL refuses their concurrent queries.',
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
            $this->fail();
        } catch (ConnectionPoolExhausted $exception) {
            $this->assertSame(
                'All 2 connections of the connection pool for `' . $this->connectionName() . '` are in use by this process and the executions running concurrently in it (coroutines or fibers), and none was returned. '
                . "Raise the pool size where the connection is registered: new Ecotone\\Api\\Dbal\\DbalConnectionFactory(['dsn' => \$dsn, 'connection_pool_size' => 20]), or SymfonyConnectionReference::defaultConnection('default')->withConnectionPoolSize(20) for a Symfony connection; every worker process may then open that many connections, so keep workers times pool size below what the database accepts. "
                . 'Or let fewer executions run at once: run a consumer on at most 2 coroutines (the `coroutines` option of ecotone:run, PollingMetadata::withCoroutines()), lower CoroutineMessageChannelBuilder::withCoroutines() for channels consumed in the sending process, on a Swoole or OpenSwoole server lower `max_coroutine`, and with fibers limit how many your event loop runs at a time and release finished Fiber objects, because a fiber keeps its connection until it is destroyed. A coroutine consuming a channel keeps one connection for as long as it runs, so the pool has to cover the consumer coroutines of the process too. Size it for the requests running at once plus the limit of every coroutine message channel in this process. '
                . "Or let a coroutine wait longer for a connection: new Ecotone\\Api\\Dbal\\DbalConnectionFactory(['dsn' => \$dsn, 'connection_pool_wait_seconds' => 10]), or SymfonyConnectionReference::defaultConnection('default')->withConnectionPoolWaitSeconds(10). A fiber cannot wait: it is refused at once.",
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

    public function test_connection_pool_wait_that_is_not_a_number_is_refused(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("`connection_pool_wait_seconds` is '5s', but it is a number of seconds. Pass a number, 0 to fail at once when every connection is in use, or leave it out for the default of 5 seconds.");

        new DbalConnectionFactory(['dsn' => self::databaseDsn(), 'connection_pool_wait_seconds' => '5s']);
    }

    public function test_negative_connection_pool_wait_is_refused(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("Connection pool wait is -1 seconds, but a wait cannot be negative. Pass 0 to fail at once when every connection is in use, a number of seconds to wait for one, or leave it out for the default of 5 seconds: `connection_pool_wait_seconds` of new Ecotone\\Api\\Dbal\\DbalConnectionFactory(['dsn' => \$dsn, 'connection_pool_wait_seconds' => 5]), or `connectionPoolWaitSeconds:` of DbalConnection::createWithCopiesForConcurrentExecutions().");

        new DbalConnectionFactory(['dsn' => self::databaseDsn(), 'connection_pool_wait_seconds' => -1]);
    }

    private function bootstrapPersonRegistration(?object $connectionFactory = null): FlowTestSupport
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

            #[CommandHandler]
            public function registerByClass(RegisterPersonInExecution $command, PersonRegister $personRegister, EventBus $eventBus): void
            {
                $this->register($command->registration, $personRegister, $eventBus);
            }

            #[Asynchronous('registrations')]
            #[CommandHandler('person.registerLater', endpointId: 'person.registerLater.endpoint')]
            public function registerLater(array $command, PersonRegister $personRegister, EventBus $eventBus): void
            {
                $this->register($command, $personRegister, $eventBus);
            }

            #[QueryHandler('person.names')]
            public function names(#[Reference] PersonRegister $personRegister): array
            {
                return $personRegister->names();
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
            [$registration::class, PersonRegister::class, PersonRegistrationGateway::class],
            [$registration, DbalConnectionFactory::class => $connectionFactory ?? new DbalConnectionFactory(self::databaseDsn())],
            [
                DbalConfiguration::createWithDefaults(),
                DbalBackedMessageChannelBuilder::create('welcomes')->withReceiveTimeout(1),
                SimpleMessageChannelBuilder::createQueueChannel('registrations'),
            ],
            [ModulePackageList::DBAL_PACKAGE],
        );
    }

    private function connectionName(): string
    {
        $parameters = $this->getConnection()->getParams();

        return sprintf('%s://%s/%s', $parameters['driver'], $parameters['host'] ?? 'localhost', $parameters['dbname'] ?? $parameters['path'] ?? '');
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

interface PersonRegistrationGateway
{
    #[DbalTransaction]
    #[BusinessMethod('person.register')]
    public function register(array $command): void;
}

final class RegisterPersonInExecution
{
    public function __construct(public array $registration)
    {
    }
}

final class PersonWasRegistered
{
    public function __construct(public string $name)
    {
    }
}
