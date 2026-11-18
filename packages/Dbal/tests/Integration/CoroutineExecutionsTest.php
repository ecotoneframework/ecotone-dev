<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

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
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\FetchMode;
use Ecotone\Api\ExtensionObject\InstantRetryConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ConfigurationException;
use OpenSwoole\Coroutine;
use OpenSwoole\Runtime;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use RuntimeException;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Throwable;

/**
 * licence Apache-2.0
 * @internal
 */
#[RequiresPhpExtension('openswoole')]
final class CoroutineExecutionsTest extends DbalMessagingTestCase
{
    public function test_coroutines_keep_their_own_headers_and_transaction(): void
    {
        $ecotone = $this->bootstrapMemberRegistration(connectionPoolSize: 10);

        $this->runCoroutines(
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

        $this->runCoroutines(
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

        $this->runCoroutines(
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

        $this->runCoroutines(
            fn () => $ecotone->sendCommandWithRouting('member.registerInTransactionLeftOpen', ['memberId' => 1, 'name' => 'left open']),
        );
        $this->runCoroutines(
            fn () => $ecotone->sendCommandWithRouting('member.register', ['memberId' => 2, 'name' => 'second']),
        );

        $this->assertSame(['second'], $ecotone->getGateway(MemberRegister::class)->names());
    }

    public function test_coroutine_is_refused_when_no_connection_is_returned_in_time(): void
    {
        $ecotone = $this->bootstrapMemberRegistration(connectionPoolSize: 2);
        $refusal = null;

        $this->runCoroutines(
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

        $this->runCoroutines(
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

    public function test_postgresql_is_refused_in_a_coroutine_that_cannot_wait_on_its_queries(): void
    {
        $ecotone = $this->bootstrapMemberRegistration(connectionPoolSize: 2, database: 'pgsql');
        $refusal = null;

        $this->runCoroutines(
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
            '` is PostgreSQL, and this coroutine runtime does not let a coroutine wait on a PostgreSQL query: the query stops every coroutine of the process. '
            . 'A coroutine waiting for a row that another coroutine of the same process has locked then stops that one too, so neither ever finishes. '
            . 'Use MySQL or MariaDB, which coroutines wait on, run on Swoole 5.1 or newer with SWOOLE_HOOK_PDO_PGSQL among the enabled hooks, or handle one message at a time per process: in Swoole or OpenSwoole set `enable_coroutine` to false.',
            (string) $refusal
        );
        $this->assertSame([], $ecotone->getGateway(MemberRegister::class)->names());
    }

    private static function dsnOf(string $database): string
    {
        foreach ([getenv('DATABASE_DSN'), getenv('SECONDARY_DATABASE_DSN')] as $dsn) {
            if (is_string($dsn) && str_starts_with($dsn, $database)) {
                return $dsn;
            }
        }

        throw new RuntimeException("The coroutine tests need a {$database} database in DATABASE_DSN or SECONDARY_DATABASE_DSN.");
    }

    private function runCoroutines(callable ...$coroutines): void
    {
        $failure = null;
        Runtime::enableCoroutine(Runtime::HOOK_ALL);
        try {
            Coroutine::run(static function () use ($coroutines, &$failure): void {
                foreach ($coroutines as $coroutine) {
                    Coroutine::create(static function () use ($coroutine, &$failure): void {
                        try {
                            $coroutine();
                        } catch (Throwable $exception) {
                            $failure ??= $exception;
                        }
                    });
                }
            });
        } finally {
            Runtime::enableCoroutine(0);
        }

        if ($failure !== null) {
            throw $failure;
        }
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
