<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Doctrine\DBAL\Connection;
use Ecotone\Api\Attribute\Apply;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Dbal\Attribute\CurrentConnection;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Gateway\GatheredGroups;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Modelling\WithAggregateVersioning;
use Ecotone\Api\Projecting\FromAggregateStream;
use Ecotone\Api\Projecting\PartitionAggregateId;
use Ecotone\Api\Projecting\Partitioned;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Projecting\ProjectionRebuild;
use Ecotone\Api\Projecting\ProjectionReset;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 *
 * @internal
 */
#[RequiresMethod(Coroutine::class, 'create')]
final class CoroutineExecutionsTest extends TestCase
{
    private const ACCOUNTS = 12;

    public static function databases(): iterable
    {
        yield 'mysql' => [(string) getenv('DATABASE_MYSQL')];
        yield 'mariadb' => [(string) getenv('DATABASE_MARIADB')];
        if (extension_loaded('swoole')) {
            yield 'postgresql' => [str_starts_with((string) getenv('DATABASE_DSN'), 'pgsql') ? (string) getenv('DATABASE_DSN') : (string) getenv('SECONDARY_DATABASE_DSN')];
        }
    }

    #[DataProvider('databases')]
    public function test_consumer_coroutines_of_a_partitioned_projection_project_partitions_at_once_and_every_event_once(string $databaseDsn): void
    {
        $projection = new #[Projection('coroutine_account_names'), Partitioned, Asynchronous('projections'), FromAggregateStream(CoroutineAccount::class)] class () {
            private int $projectedAtOnce = 0;
            private int $mostProjectedAtOnce = 0;

            #[EventHandler]
            public function whenOpened(CoroutineAccountOpened $event, #[CurrentConnection] Connection $connection): void
            {
                $this->projectedAtOnce++;
                $this->mostProjectedAtOnce = max($this->mostProjectedAtOnce, $this->projectedAtOnce);
                try {
                    $connection->executeStatement('INSERT INTO coroutine_account_names VALUES (?, ?)', [$event->accountId, $event->name]);
                    usleep(50_000);
                } finally {
                    $this->projectedAtOnce--;
                }
            }

            #[QueryHandler('coroutine_account_names.all')]
            public function all(#[CurrentConnection] Connection $connection): array
            {
                return $connection->fetchAllKeyValue('SELECT account_id, name FROM coroutine_account_names ORDER BY account_id');
            }

            #[QueryHandler('coroutine_account_names.mostProjectedAtOnce')]
            public function mostProjectedAtOnce(): int
            {
                return $this->mostProjectedAtOnce;
            }
        };
        $ecotone = $this->bootstrap($databaseDsn, $projection);
        $this->openAccounts($ecotone);

        $ecotone->run('projections', ExecutionPollingMetadata::createWithFinishWhenNoMessages()->withCoroutines(3));

        $this->assertSame($this->namesOfAccounts(), $ecotone->sendQueryWithRouting('coroutine_account_names.all'));
        $this->assertSame(3, $ecotone->sendQueryWithRouting('coroutine_account_names.mostProjectedAtOnce'));
    }

    #[DataProvider('databases')]
    public function test_consumer_coroutines_of_a_global_projection_project_every_event_once(string $databaseDsn): void
    {
        $projection = new #[Projection('coroutine_account_names'), Asynchronous('projections'), FromAggregateStream(CoroutineAccount::class)] class () {
            #[EventHandler]
            public function whenOpened(CoroutineAccountOpened $event, #[CurrentConnection] Connection $connection): void
            {
                $connection->executeStatement('INSERT INTO coroutine_account_names VALUES (?, ?)', [$event->accountId, $event->name]);
                usleep(50_000);
            }

            #[QueryHandler('coroutine_account_names.all')]
            public function all(#[CurrentConnection] Connection $connection): array
            {
                return $connection->fetchAllKeyValue('SELECT account_id, name FROM coroutine_account_names ORDER BY account_id');
            }
        };
        $ecotone = $this->bootstrap($databaseDsn, $projection);
        $this->openAccounts($ecotone);

        $ecotone->run('projections', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false)->withCoroutines(3));

        $this->assertSame($this->namesOfAccounts(), $ecotone->sendQueryWithRouting('coroutine_account_names.all'));
    }

    #[DataProvider('databases')]
    public function test_batches_of_a_rebuild_are_executed_at_once_by_consumer_coroutines_and_complete_the_rebuild_once(string $databaseDsn): void
    {
        $projection = new #[Projection('coroutine_account_names'), Partitioned, ProjectionRebuild(partitionBatchSize: 2, asyncChannelName: 'rebuild', batchCompletionChannelName: 'rebuild_reports'), FromAggregateStream(CoroutineAccount::class)] class () {
            private int $resetAtOnce = 0;
            private int $mostResetAtOnce = 0;

            #[EventHandler]
            public function whenOpened(CoroutineAccountOpened $event, #[CurrentConnection] Connection $connection): void
            {
                $connection->executeStatement('INSERT INTO coroutine_account_names VALUES (?, ?)', [$event->accountId, $event->name]);
            }

            #[ProjectionReset]
            public function reset(#[PartitionAggregateId] string $accountId, #[CurrentConnection] Connection $connection): void
            {
                $this->resetAtOnce++;
                $this->mostResetAtOnce = max($this->mostResetAtOnce, $this->resetAtOnce);
                try {
                    $connection->executeStatement('DELETE FROM coroutine_account_names WHERE account_id = ?', [$accountId]);
                    usleep(50_000);
                } finally {
                    $this->resetAtOnce--;
                }
            }

            #[QueryHandler('coroutine_account_names.all')]
            public function all(#[CurrentConnection] Connection $connection): array
            {
                return $connection->fetchAllKeyValue('SELECT account_id, name FROM coroutine_account_names ORDER BY account_id');
            }

            #[QueryHandler('coroutine_account_names.mostResetAtOnce')]
            public function mostResetAtOnce(): int
            {
                return $this->mostResetAtOnce;
            }
        };
        $ecotone = $this->bootstrap($databaseDsn, $projection);
        $this->openAccounts($ecotone);

        $ecotone->runConsoleCommand('ecotone:projection:rebuild', ['name' => 'coroutine_account_names']);
        $ecotone->run('rebuild', ExecutionPollingMetadata::createWithFinishWhenNoMessages()->withCoroutines(3));
        $ecotone->run('rebuild_reports', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        $this->assertSame(0, $ecotone->getGateway(DeadLetterGateway::class)->count());
        $this->assertSame([], $ecotone->getGateway(GatheredGroups::class)->listOpenGroups('coroutine_account_names.rebuild'));
        $this->assertSame([[6, 6, [], 0]], array_map(static fn (array $group): array => [$group['expectedSize'], $group['recorded'], $group['missingPartNumbers'], $group['latePartCount']], $ecotone->getGateway(GatheredGroups::class)->listClosedGroups('coroutine_account_names.rebuild')));
        $this->assertSame($this->namesOfAccounts(), $ecotone->sendQueryWithRouting('coroutine_account_names.all'));
        $this->assertSame(3, $ecotone->sendQueryWithRouting('coroutine_account_names.mostResetAtOnce'));
    }

    #[DataProvider('databases')]
    public function test_commands_of_one_event_sourced_aggregate_handled_at_once_by_consumer_coroutines_record_every_event_once(string $databaseDsn): void
    {
        $ecotone = $this->bootstrap($databaseDsn);
        $ecotone->sendCommand(new OpenCoroutineAccount('account-01', 'Account 1'));
        for ($deposit = 1; $deposit <= 6; $deposit++) {
            $ecotone->sendCommandWithRouting('coroutine_account.deposit', $deposit, metadata: ['aggregate.id' => 'account-01']);
        }

        $ecotone->run('accounts', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false)->withCoroutines(3));
        $deadLetters = $ecotone->getGateway(DeadLetterGateway::class);
        while ($deadLetters->count() > 0) {
            $deadLetters->replayAll();
            $ecotone->run('accounts', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));
        }

        $deposits = $ecotone->sendQueryWithRouting('coroutine_account.deposits', metadata: ['aggregate.id' => 'account-01']);
        sort($deposits);
        $this->assertSame([1, 2, 3, 4, 5, 6], $deposits);
    }

    private function openAccounts(FlowTestSupport $ecotone): void
    {
        for ($account = 1; $account <= self::ACCOUNTS; $account++) {
            $ecotone->sendCommand(new OpenCoroutineAccount(sprintf('account-%02d', $account), "Account {$account}"));
        }
    }

    private function namesOfAccounts(): array
    {
        $names = [];
        for ($account = 1; $account <= self::ACCOUNTS; $account++) {
            $names[sprintf('account-%02d', $account)] = "Account {$account}";
        }

        return $names;
    }

    private function bootstrap(string $databaseDsn, object ...$projections): FlowTestSupport
    {
        $connection = (new DbalConnectionFactory($databaseDsn))->createContext()->getDbalConnection();
        EventSourcingMessagingTestCase::clearDataTables($connection);
        $connection->executeStatement('CREATE TABLE coroutine_account_names (account_id VARCHAR(36) PRIMARY KEY, name VARCHAR(255))');
        $connection->close();
        $converter = new class () {
            #[Converter]
            public function fromOpened(CoroutineAccountOpened $event): array
            {
                return ['accountId' => $event->accountId, 'name' => $event->name];
            }

            #[Converter]
            public function toOpened(array $event): CoroutineAccountOpened
            {
                return new CoroutineAccountOpened($event['accountId'], $event['name']);
            }

            #[Converter]
            public function fromDeposited(CoroutineAccountDeposited $event): array
            {
                return ['accountId' => $event->accountId, 'amount' => $event->amount];
            }

            #[Converter]
            public function toDeposited(array $event): CoroutineAccountDeposited
            {
                return new CoroutineAccountDeposited($event['accountId'], $event['amount']);
            }
        };

        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [CoroutineAccount::class, $converter::class, ...array_map(static fn (object $projection): string => $projection::class, $projections)],
            containerOrAvailableServices: [...$projections, $converter, DbalConnectionFactory::class => new DbalConnectionFactory(['dsn' => $databaseDsn, 'connection_pool_size' => 10])],
            extensionObjects: [
                DbalBackedMessageChannelBuilder::create('projections'),
                DbalBackedMessageChannelBuilder::create('accounts'),
                DbalBackedMessageChannelBuilder::create('rebuild'),
                DbalBackedMessageChannelBuilder::create('rebuild_reports'),
                DbalConfiguration::createWithDefaults(),
                ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel('dbal_dead_letter'),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}

final class OpenCoroutineAccount
{
    public function __construct(public readonly string $accountId, public readonly string $name)
    {
    }
}

final class CoroutineAccountOpened
{
    public function __construct(public readonly string $accountId, public readonly string $name)
    {
    }
}

final class CoroutineAccountDeposited
{
    public function __construct(public readonly string $accountId, public readonly int $amount)
    {
    }
}

#[EventSourcingAggregate]
final class CoroutineAccount
{
    use WithAggregateVersioning;

    #[Identifier]
    private string $accountId;
    private array $deposits = [];

    #[CommandHandler]
    public static function open(OpenCoroutineAccount $command): array
    {
        return [new CoroutineAccountOpened($command->accountId, $command->name)];
    }

    #[Asynchronous('accounts')]
    #[CommandHandler('coroutine_account.deposit', endpointId: 'coroutine_account.deposit.endpoint')]
    public function deposit(int $amount): array
    {
        usleep(20_000);

        return [new CoroutineAccountDeposited($this->accountId, $amount)];
    }

    #[QueryHandler('coroutine_account.deposits')]
    public function deposits(): array
    {
        return $this->deposits;
    }

    #[Apply]
    public function applyOpened(CoroutineAccountOpened $event): void
    {
        $this->accountId = $event->accountId;
    }

    #[Apply]
    public function applyDeposited(CoroutineAccountDeposited $event): void
    {
        $this->deposits[] = $event->amount;
    }
}
