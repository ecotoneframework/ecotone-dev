<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Attribute\WithoutDatabaseTransaction;
use Ecotone\Api\Dbal\Attribute\DbalQuery;
use Ecotone\Api\Dbal\Attribute\DbalWrite;
use Ecotone\Api\Dbal\ConnectionPoolExhausted;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\DbalDeadLetterBuilder;
use Ecotone\Api\Dbal\FetchMode;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\InstantRetryConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Test\CoroutineTesting;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use RuntimeException;
use Swoole\Coroutine;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
#[RequiresMethod(Coroutine::class, 'create')]
final class CoroutineExecutionsTest extends DbalMessagingTestCase
{
    public function test_coroutines_keep_their_own_headers_and_transaction(): void
    {
        $ecotone = $this->bootstrapMemberRegistration(connectionPoolSize: 10);

        CoroutineTesting::runAtOnce(
            function () use ($ecotone): void {
                try {
                    $ecotone->sendCommandWithRouting('member.register', ['memberId' => 1, 'name' => 'refused', 'pauseSeconds' => 0.2, 'fail' => true], metadata: ['tenant' => 'tenant-of-refused']);
                } catch (RuntimeException) {
                }
            },
            function () use ($ecotone): void {
                usleep(50_000);
                $ecotone->sendCommandWithRouting('member.register', ['memberId' => 2, 'name' => 'accepted']);
            },
        );

        $this->assertSame(['accepted'], $ecotone->getGateway(MemberRegister::class)->names());
        $this->assertSame(['accepted' => null], $ecotone->sendQueryWithRouting('member.tenantSeenByEventHandler'));
    }

    public function test_coroutine_waits_for_a_connection_another_coroutine_returns(): void
    {
        $ecotone = $this->bootstrapMemberRegistration(connectionPoolSize: 2);

        CoroutineTesting::runAtOnce(
            fn () => $ecotone->sendCommandWithRouting('member.register', ['memberId' => 1, 'name' => 'first', 'pauseSeconds' => 0.2]),
            function () use ($ecotone): void {
                usleep(50_000);
                $ecotone->sendCommandWithRouting('member.register', ['memberId' => 2, 'name' => 'second']);
            },
        );

        $this->assertSame(['first', 'second'], $ecotone->getGateway(MemberRegister::class)->names());
    }

    public function test_waiting_coroutine_is_given_a_connection_when_the_returned_one_was_closed(): void
    {
        $ecotone = $this->bootstrapMemberRegistration(connectionPoolSize: 2);

        CoroutineTesting::runAtOnce(
            fn () => $ecotone->sendCommandWithRouting('member.closeConnection', ['pauseSeconds' => 0.2]),
            function () use ($ecotone): void {
                usleep(50_000);
                $ecotone->sendCommandWithRouting('member.register', ['memberId' => 2, 'name' => 'second']);
            },
        );

        $this->assertSame(['second'], $ecotone->getGateway(MemberRegister::class)->names());
    }

    public function test_transaction_a_coroutine_left_open_is_rolled_back_before_its_connection_is_reused(): void
    {
        $ecotone = $this->bootstrapMemberRegistration(connectionPoolSize: 2);

        CoroutineTesting::runAtOnce(
            fn () => $ecotone->sendCommandWithRouting('member.registerInTransactionLeftOpen', ['memberId' => 1, 'name' => 'left open']),
        );
        CoroutineTesting::runAtOnce(
            fn () => $ecotone->sendCommandWithRouting('member.register', ['memberId' => 2, 'name' => 'second']),
        );

        $this->assertSame(['second'], $ecotone->getGateway(MemberRegister::class)->names());
    }

    public function test_coroutine_is_refused_when_no_connection_is_returned_in_time(): void
    {
        $ecotone = $this->bootstrapMemberRegistration(connectionPoolSize: 2);
        $refusal = null;

        CoroutineTesting::runAtOnce(
            fn () => $ecotone->sendCommandWithRouting('member.register', ['memberId' => 1, 'name' => 'first', 'pauseSeconds' => 5.5]),
            function () use ($ecotone, &$refusal): void {
                usleep(50_000);
                try {
                    $ecotone->sendCommandWithRouting('member.register', ['memberId' => 2, 'name' => 'second']);
                } catch (ConnectionPoolExhausted $exception) {
                    $refusal = $exception->getMessage();
                }
            },
        );

        $this->assertStringContainsString('and none was returned within 5 seconds', (string) $refusal);
        $this->assertSame(['first'], $ecotone->getGateway(MemberRegister::class)->names());
    }

    public function test_coroutine_started_by_a_handler_propagates_its_headers_and_stores_outside_of_its_transaction(): void
    {
        $ecotone = $this->bootstrapMemberRegistration(connectionPoolSize: 10);

        CoroutineTesting::runAtOnce(
            function () use ($ecotone): void {
                try {
                    $ecotone->sendCommandWithRouting('member.registerWithCoroutine', ['memberId' => 1, 'name' => 'registered by handler'], metadata: ['tenant' => 'tenant-of-handler']);
                } catch (RuntimeException) {
                }
            },
        );

        $this->assertSame(['registered by coroutine'], $ecotone->getGateway(MemberRegister::class)->names());
        $this->assertSame([], $ecotone->sendQueryWithRouting('member.namesReadByCoroutine'));
        $this->assertSame(['registered by coroutine' => 'tenant-of-handler'], $ecotone->sendQueryWithRouting('member.tenantSeenByEventHandler'));
    }

    public function test_consumer_coroutines_of_a_database_channel_handle_messages_at_once_each_in_its_own_transaction(): void
    {
        $ecotone = $this->bootstrapEnrolments(connectionPoolSize: 10, coroutines: 3);
        $this->enrolFiveAcceptedAndOneRefused($ecotone);

        $ecotone->run('enrolments', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));

        $this->assertSame(['first', 'second', 'third', 'fourth', 'fifth'], $ecotone->getGateway(MemberRegister::class)->names());
        $this->assertSame(3, $ecotone->sendQueryWithRouting('member.mostEnrolledAtOnce'));
        $this->assertSame(1, $ecotone->getGateway(DeadLetterGateway::class)->count());
    }

    #[RequiresPhpExtension('swoole')]
    #[Group('swoole')]
    public function test_consumer_coroutines_of_a_postgresql_channel_handle_messages_at_once_each_in_its_own_transaction(): void
    {
        $ecotone = $this->bootstrapEnrolments(connectionPoolSize: 10, coroutines: 3, database: 'pgsql');
        $this->enrolFiveAcceptedAndOneRefused($ecotone);

        $ecotone->run('enrolments', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));

        $this->assertSame(['first', 'second', 'third', 'fourth', 'fifth'], $ecotone->getGateway(MemberRegister::class)->names());
        $this->assertSame(3, $ecotone->sendQueryWithRouting('member.mostEnrolledAtOnce'));
        $this->assertSame(1, $ecotone->getGateway(DeadLetterGateway::class)->count());
    }

    public function test_consumer_with_more_coroutines_than_pooled_connections_is_refused_naming_the_coroutines(): void
    {
        $ecotone = $this->bootstrapEnrolments(connectionPoolSize: 2, coroutines: 3);
        $this->enrolFiveAcceptedAndOneRefused($ecotone);

        $this->expectException(ConnectionPoolExhausted::class);
        $this->expectExceptionMessage('Or let fewer executions run at once: run a consumer on at most 2 coroutines (the `coroutines` option of ecotone:run, PollingMetadata::withCoroutines())');

        $ecotone->run('enrolments', ExecutionPollingMetadata::createWithDefaults()->withFinishWhenNoMessages(false)->withExecutionTimeLimitInMilliseconds(20_000));
    }

    public function test_coroutines_wait_on_mysql_queries_without_stopping_each_other(): void
    {
        $ecotone = $this->bootstrapMemberRegistration(connectionPoolSize: 2);

        CoroutineTesting::runAtOnce(
            fn () => $ecotone->sendCommandWithRouting('member.waitOnDatabase', ['query' => 'SELECT SLEEP(0.2)']),
            fn () => $ecotone->sendCommandWithRouting('member.waitOnDatabase', ['query' => 'SELECT SLEEP(0.2)']),
        );

        $this->assertSame(2, $ecotone->sendQueryWithRouting('member.mostWaitingOnDatabaseAtOnce'));
    }

    #[RequiresPhpExtension('swoole')]
    #[Group('swoole')]
    public function test_coroutines_wait_on_postgresql_queries_without_stopping_each_other(): void
    {
        $ecotone = $this->bootstrapMemberRegistration(connectionPoolSize: 2, database: 'pgsql');

        CoroutineTesting::runAtOnce(
            fn () => $ecotone->sendCommandWithRouting('member.waitOnDatabase', ['query' => 'SELECT pg_sleep(0.2)']),
            fn () => $ecotone->sendCommandWithRouting('member.waitOnDatabase', ['query' => 'SELECT pg_sleep(0.2)']),
        );

        $this->assertSame(2, $ecotone->sendQueryWithRouting('member.mostWaitingOnDatabaseAtOnce'));
    }

    #[RequiresPhpExtension('swoole')]
    #[Group('swoole')]
    public function test_postgresql_coroutines_keep_their_own_transaction(): void
    {
        $ecotone = $this->bootstrapMemberRegistration(connectionPoolSize: 10, database: 'pgsql');

        CoroutineTesting::runAtOnce(
            function () use ($ecotone): void {
                try {
                    $ecotone->sendCommandWithRouting('member.register', ['memberId' => 1, 'name' => 'refused', 'pauseSeconds' => 0.2, 'fail' => true]);
                } catch (RuntimeException) {
                }
            },
            function () use ($ecotone): void {
                usleep(50_000);
                $ecotone->sendCommandWithRouting('member.register', ['memberId' => 2, 'name' => 'accepted']);
            },
        );

        $this->assertSame(['accepted'], $ecotone->getGateway(MemberRegister::class)->names());
    }

    #[RequiresPhpExtension('swoole')]
    #[Group('swoole')]
    public function test_sqlite_coroutine_waits_for_the_coroutine_writing_and_both_keep_their_own_transaction(): void
    {
        $ecotone = $this->bootstrapMemberRegistration(connectionPoolSize: 10, database: 'sqlite');

        CoroutineTesting::runAtOnce(
            function () use ($ecotone): void {
                try {
                    $ecotone->sendCommandWithRouting('member.register', ['memberId' => 1, 'name' => 'refused', 'pauseSeconds' => 0.2, 'fail' => true]);
                } catch (RuntimeException) {
                }
            },
            function () use ($ecotone): void {
                usleep(50_000);
                $ecotone->sendCommandWithRouting('member.register', ['memberId' => 2, 'name' => 'accepted']);
            },
            function () use ($ecotone): void {
                usleep(60_000);
                $ecotone->sendCommandWithRouting('member.register', ['memberId' => 3, 'name' => 'accepted too']);
            },
        );

        $this->assertSame(['accepted', 'accepted too'], $ecotone->getGateway(MemberRegister::class)->names());
    }

    #[RequiresPhpExtension('swoole')]
    #[Group('swoole')]
    public function test_postgresql_is_refused_in_a_coroutine_with_the_postgresql_hook_switched_off(): void
    {
        $ecotone = $this->bootstrapMemberRegistration(connectionPoolSize: 2, database: 'pgsql');

        $this->assertPostgresqlIsRefused($ecotone, hooks: SWOOLE_HOOK_ALL ^ constant('SWOOLE_HOOK_PDO_PGSQL'), because: 'SWOOLE_HOOK_PDO_PGSQL is not among the enabled hooks, so a coroutine cannot wait on a PostgreSQL query');
    }

    #[RequiresPhpExtension('openswoole')]
    #[Group('openswoole')]
    public function test_postgresql_is_refused_in_a_coroutine_that_cannot_wait_on_its_queries(): void
    {
        $ecotone = $this->bootstrapMemberRegistration(connectionPoolSize: 2, database: 'pgsql');

        $this->assertPostgresqlIsRefused($ecotone, hooks: SWOOLE_HOOK_ALL, because: 'OpenSwoole does not let a coroutine wait on a PostgreSQL query');
    }

    private function assertPostgresqlIsRefused(FlowTestSupport $ecotone, int $hooks, string $because): void
    {
        $refusal = null;

        CoroutineTesting::runAtOnceWithHooks(
            $hooks,
            function () use ($ecotone, &$refusal): void {
                try {
                    $ecotone->sendCommandWithRouting('member.register', ['memberId' => 1, 'name' => 'first']);
                } catch (ConfigurationException $exception) {
                    $refusal = $exception->getMessage();
                }
            },
        );

        $this->assertStringStartsWith('Database connection `pdo_pgsql://', (string) $refusal);
        $this->assertStringEndsWith(
            "` is PostgreSQL, and {$because}: the query stops every coroutine of the process. "
            . 'A coroutine waiting for a row that another coroutine of the same process has locked then stops that one too, so neither ever finishes. '
            . 'Use MySQL or MariaDB, which coroutines wait on; or run on Swoole 5.1 or newer built with --enable-swoole-pgsql and with SWOOLE_HOOK_PDO_PGSQL among the enabled hooks (SWOOLE_HOOK_ALL has it); '
            . 'or handle one message at a time per process: run the consumer without the `coroutines` option of ecotone:run and without PollingMetadata::withCoroutines(), and set `enable_coroutine` to false on a Swoole or OpenSwoole server.',
            (string) $refusal
        );
        $this->assertSame([], $ecotone->getGateway(MemberRegister::class)->names());
    }

    private static function dsnOf(string $database): string
    {
        if ($database === 'sqlite') {
            return 'sqlite:////tmp/ecotone_coroutine_executions.db';
        }

        foreach ([getenv('DATABASE_DSN'), getenv('SECONDARY_DATABASE_DSN')] as $dsn) {
            if (is_string($dsn) && str_starts_with($dsn, $database)) {
                return $dsn;
            }
        }

        throw new RuntimeException("The coroutine tests need a {$database} database in DATABASE_DSN or SECONDARY_DATABASE_DSN.");
    }

    private function enrolFiveAcceptedAndOneRefused(FlowTestSupport $ecotone): void
    {
        foreach (['first', 'second', 'refused', 'third', 'fourth', 'fifth'] as $memberId => $name) {
            $ecotone->sendCommandWithRouting('member.enrol', ['memberId' => $memberId + 1, 'name' => $name]);
        }
    }

    private function bootstrapEnrolments(int $connectionPoolSize, int $coroutines, string $database = 'mysql'): FlowTestSupport
    {
        $databaseDsn = self::dsnOf($database);
        $connection = (new DbalConnectionFactory($databaseDsn))->createContext()->getDbalConnection();
        $connection->executeStatement('DROP TABLE IF EXISTS members');
        $connection->executeStatement('CREATE TABLE members (member_id INTEGER PRIMARY KEY, name VARCHAR(255))');
        $connection->close();
        $enrolment = new class () {
            private int $enrolledAtOnce = 0;
            private int $mostEnrolledAtOnce = 0;

            #[Asynchronous('enrolments')]
            #[CommandHandler('member.enrol', endpointId: 'member.enrol.endpoint')]
            public function enrol(array $command, MemberRegister $memberRegister): void
            {
                $this->enrolledAtOnce++;
                $this->mostEnrolledAtOnce = max($this->mostEnrolledAtOnce, $this->enrolledAtOnce);
                try {
                    $memberRegister->register($command['memberId'], $command['name']);
                    usleep(50_000);
                    if ($command['name'] === 'refused') {
                        throw new RuntimeException('Enrolment was refused');
                    }
                } finally {
                    $this->enrolledAtOnce--;
                }
            }

            #[QueryHandler('member.mostEnrolledAtOnce')]
            public function mostEnrolledAtOnce(): int
            {
                return $this->mostEnrolledAtOnce;
            }
        };

        return $this->bootstrapFlowTesting(
            [$enrolment::class, MemberRegister::class],
            [$enrolment, DbalConnectionFactory::class => new DbalConnectionFactory(['dsn' => $databaseDsn, 'connection_pool_size' => $connectionPoolSize])],
            [
                DbalConfiguration::createWithDefaults(),
                DbalBackedMessageChannelBuilder::create('enrolments'),
                PollingMetadata::create('enrolments')->withErrorChannelName(DbalDeadLetterBuilder::STORE_CHANNEL)->withCoroutines($coroutines),
                InstantRetryConfiguration::createWithDefaults()->withCommandBusRetry(false),
            ],
            [ModulePackageList::DBAL_PACKAGE],
        );
    }

    private function bootstrapMemberRegistration(int $connectionPoolSize, string $database = 'mysql'): FlowTestSupport
    {
        $databaseDsn = self::dsnOf($database);
        $connection = (new DbalConnectionFactory($databaseDsn))->createContext()->getDbalConnection();
        $connection->executeStatement('DROP TABLE IF EXISTS members');
        $connection->executeStatement('CREATE TABLE members (member_id INTEGER PRIMARY KEY, name VARCHAR(255))');
        $connection->close();
        $registration = new class () {
            private array $tenantSeenByEventHandler = [];
            private array $namesReadByCoroutine = ['not read'];
            private int $waitingOnDatabaseAtOnce = 0;
            private int $mostWaitingOnDatabaseAtOnce = 0;

            #[CommandHandler('member.register')]
            public function register(array $command, MemberRegister $memberRegister, EventBus $eventBus): void
            {
                $memberRegister->register($command['memberId'], $command['name']);
                if (isset($command['pauseSeconds'])) {
                    usleep((int) ($command['pauseSeconds'] * 1_000_000));
                }
                if ($command['fail'] ?? false) {
                    throw new RuntimeException('Registration was refused');
                }

                $eventBus->publish(new MemberWasRegistered($command['name']));
            }

            #[CommandHandler('member.registerWithCoroutine')]
            public function registerWithCoroutine(array $command, MemberRegister $memberRegister, EventBus $eventBus): void
            {
                $memberRegister->register($command['memberId'], $command['name']);
                $finished = new Coroutine\Channel(1);
                Coroutine::create(function () use ($command, $memberRegister, $eventBus, $finished): void {
                    $this->namesReadByCoroutine = $memberRegister->names();
                    $memberRegister->register($command['memberId'] + 1, 'registered by coroutine');
                    $eventBus->publish(new MemberWasRegistered('registered by coroutine'));
                    $finished->push(true);
                });
                $finished->pop();

                throw new RuntimeException('Registration was refused');
            }

            #[WithoutDatabaseTransaction]
            #[CommandHandler('member.waitOnDatabase')]
            public function waitOnDatabase(array $command, #[Reference] DbalConnectionFactory $connectionFactory): void
            {
                $this->waitingOnDatabaseAtOnce++;
                $this->mostWaitingOnDatabaseAtOnce = max($this->mostWaitingOnDatabaseAtOnce, $this->waitingOnDatabaseAtOnce);
                $connectionFactory->createContext()->getDbalConnection()->fetchOne($command['query']);
                $this->waitingOnDatabaseAtOnce--;
            }

            #[QueryHandler('member.mostWaitingOnDatabaseAtOnce')]
            public function mostWaitingOnDatabaseAtOnce(): int
            {
                return $this->mostWaitingOnDatabaseAtOnce;
            }

            #[QueryHandler('member.namesReadByCoroutine')]
            public function namesReadByCoroutine(): array
            {
                return $this->namesReadByCoroutine;
            }

            #[WithoutDatabaseTransaction]
            #[CommandHandler('member.registerInTransactionLeftOpen')]
            public function registerInTransactionLeftOpen(array $command, MemberRegister $memberRegister, #[Reference] DbalConnectionFactory $connectionFactory): void
            {
                $connectionFactory->createContext()->getDbalConnection()->beginTransaction();
                $memberRegister->register($command['memberId'], $command['name']);
            }

            #[WithoutDatabaseTransaction]
            #[CommandHandler('member.closeConnection')]
            public function closeConnection(array $command, #[Reference] DbalConnectionFactory $connectionFactory): void
            {
                $connection = $connectionFactory->createContext()->getDbalConnection();
                $connection->fetchOne('SELECT 1');
                usleep((int) ($command['pauseSeconds'] * 1_000_000));
                $connection->close();
            }

            #[EventHandler]
            public function when(MemberWasRegistered $event, #[Header('tenant')] ?string $tenant = null): void
            {
                $this->tenantSeenByEventHandler[$event->name] = $tenant;
            }

            #[QueryHandler('member.tenantSeenByEventHandler')]
            public function tenantSeenByEventHandler(): array
            {
                return $this->tenantSeenByEventHandler;
            }
        };

        return $this->bootstrapFlowTesting(
            [$registration::class, MemberRegister::class],
            [$registration, DbalConnectionFactory::class => new DbalConnectionFactory(['dsn' => $databaseDsn, 'connection_pool_size' => $connectionPoolSize])],
            [
                DbalConfiguration::createWithDefaults(),
                InstantRetryConfiguration::createWithDefaults()->withCommandBusRetry(false),
            ],
            [ModulePackageList::DBAL_PACKAGE],
        );
    }
}

interface MemberRegister
{
    #[DbalWrite('INSERT INTO members (member_id, name) VALUES (:memberId, :name)')]
    public function register(int $memberId, string $name): void;

    #[DbalQuery('SELECT name FROM members ORDER BY member_id', fetchMode: FetchMode::FIRST_COLUMN)]
    public function names(): array;
}

final class MemberWasRegistered
{
    public function __construct(public string $name)
    {
    }
}
