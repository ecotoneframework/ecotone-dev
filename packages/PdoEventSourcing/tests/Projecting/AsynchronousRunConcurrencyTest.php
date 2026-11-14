<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting;

use DateTimeImmutable;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Ecotone\Api\Attribute\Apply;
use Ecotone\Api\Attribute\Around;
use Ecotone\Api\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Gateway\GatheredGroups;
use Ecotone\Api\Interceptor\MethodInvocation;
use Ecotone\Api\Interceptor\Precedence;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Modelling\WithAggregateVersioning;
use Ecotone\Api\Projecting\FromAggregateStream;
use Ecotone\Api\Projecting\PartitionAggregateId;
use Ecotone\Api\Projecting\Partitioned;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Projecting\ProjectionInitialization;
use Ecotone\Api\Projecting\ProjectionRebuild;
use Ecotone\Api\Projecting\ProjectionReset;
use Ecotone\Test\LicenceTesting;
use RuntimeException;

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
final class AsynchronousRunConcurrencyTest extends ProjectingTestCase
{
    private const CONSUMERS = 4;
    private const ACCOUNTS = 8;

    public function test_batches_executed_concurrently_complete_the_rebuild_once_in_a_real_database_race(): void
    {
        if (self::getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('SQLite serialises writers with a database-wide lock, so this race between consumer processes is proved on PostgreSQL, MySQL and MariaDB only.');
        }
        $application = self::application();
        for ($account = 1; $account <= self::ACCOUNTS; $account++) {
            $application->sendCommand(new OpenConcurrentRunAccount((string) $account, "Account {$account}"));
        }

        $application->runConsoleCommand('ecotone:projection:rebuild', ['name' => 'concurrent_account_names']);
        self::runConsumerProcesses(self::CONSUMERS, 'consumeRebuildChannel');
        $deadLetters = $application->getGateway(DeadLetterGateway::class);
        $groups = $application->getGateway(GatheredGroups::class);
        $run = [...$groups->listOpenGroups('concurrent_account_names.rebuild'), ...$groups->listClosedGroups('concurrent_account_names.rebuild')];
        self::assertCount(1, $run);
        self::assertSame(4, $run[0]['recorded'] + $deadLetters->count());
        while ($deadLetters->count() > 0) {
            $deadLetters->replayAll();
            $application->run('concurrent_rebuild', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));
        }

        self::assertSame([], $application->getGateway(GatheredGroups::class)->listOpenGroups('concurrent_account_names.rebuild'));
        self::assertSame([[4, 4, [], 0]], array_map(static fn (array $group): array => [$group['expectedSize'], $group['recorded'], $group['missingPartNumbers'], $group['latePartCount']], $application->getGateway(GatheredGroups::class)->listClosedGroups('concurrent_account_names.rebuild')));
        self::assertSame(self::ACCOUNTS, $application->sendQueryWithRouting('concurrent_account_names.count'));
    }

    public static function consumeRebuildChannel(): void
    {
        $barrier = new class () {
            private bool $released = false;

            #[Around(precedence: Precedence::DATABASE_TRANSACTION_PRECEDENCE + 101, pointcut: AsynchronousRunningEndpoint::class)]
            public function awaitOtherConsumers(MethodInvocation $invocation): mixed
            {
                if (! $this->released) {
                    fwrite(STDOUT, "ready\n");
                    if (fgets(STDIN) !== "consume\n") {
                        throw new RuntimeException('Concurrent consumer start was not released.');
                    }
                    $this->released = true;
                }

                return $invocation->proceed();
            }
        };
        self::application([$barrier])->run('concurrent_rebuild', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));
    }

    private static function application(array $additionalServices = []): FlowTestSupport
    {
        $projection = new #[Projection('concurrent_account_names'), Partitioned, ProjectionRebuild(partitionBatchSize: 2, asyncChannelName: 'concurrent_rebuild'), FromAggregateStream(ConcurrentRunAccount::class)] class () {
            #[ProjectionInitialization]
            public function initialize(): void
            {
                ProjectingTestCase::getConnection()->executeStatement('CREATE TABLE IF NOT EXISTS concurrent_account_names (account_id VARCHAR(36) PRIMARY KEY, name VARCHAR(255))');
            }

            #[EventHandler]
            public function whenOpened(ConcurrentRunAccountOpened $event): void
            {
                ProjectingTestCase::getConnection()->executeStatement('INSERT INTO concurrent_account_names VALUES (?, ?)', [$event->accountId, $event->name]);
            }

            #[ProjectionReset]
            public function reset(#[PartitionAggregateId] string $accountId): void
            {
                ProjectingTestCase::getConnection()->executeStatement('DELETE FROM concurrent_account_names WHERE account_id = ?', [$accountId]);
            }

            #[QueryHandler('concurrent_account_names.count')]
            public function count(): int
            {
                return (int) ProjectingTestCase::getConnection()->fetchOne('SELECT COUNT(*) FROM concurrent_account_names');
            }
        };
        $converter = new class () {
            #[Converter]
            public function fromOpened(ConcurrentRunAccountOpened $event): array
            {
                return ['accountId' => $event->accountId, 'name' => $event->name];
            }

            #[Converter]
            public function toOpened(array $event): ConcurrentRunAccountOpened
            {
                return new ConcurrentRunAccountOpened($event['accountId'], $event['name']);
            }
        };

        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$projection::class, $converter::class, ConcurrentRunAccount::class, ...array_map(static fn (object $service): string => $service::class, $additionalServices)],
            containerOrAvailableServices: [$projection, $converter, self::getConnectionFactory(), ...$additionalServices],
            extensionObjects: [
                DbalBackedMessageChannelBuilder::create('concurrent_rebuild'),
                DbalConfiguration::createWithDefaults(),
                ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel('dbal_dead_letter'),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE],
            initialTime: new DateTimeImmutable('2026-11-14 12:00:00 UTC'),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    private static function runConsumerProcesses(int $numberOfConsumers, string $consumerMethod): void
    {
        $packageAutoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $autoload = is_file($packageAutoload) ? $packageAutoload : dirname(__DIR__, 4) . '/vendor/autoload.php';
        $code = 'require ' . var_export($autoload, true) . '; require ' . var_export(__FILE__, true) . '; ' . self::class . '::' . $consumerMethod . '();';
        $children = [];
        for ($consumer = 0; $consumer < $numberOfConsumers; $consumer++) {
            $process = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $children[] = [$process, $pipes];
        }
        try {
            $ready = array_map(static fn (array $child): string|false => fgets($child[1][1]), $children);
            foreach ($children as [$process, $pipes]) {
                fwrite($pipes[0], "consume\n");
                fclose($pipes[0]);
            }
            self::assertSame(array_fill(0, $numberOfConsumers, "ready\n"), $ready);
            foreach ($children as [$process, $pipes]) {
                self::assertSame('', stream_get_contents($pipes[1]));
                self::assertSame('', stream_get_contents($pipes[2]));
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame(0, proc_close($process));
            }
        } finally {
            foreach ($children as [$process, $pipes]) {
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
        }
    }
}

final class OpenConcurrentRunAccount
{
    public function __construct(public readonly string $accountId, public readonly string $name)
    {
    }
}

final class ConcurrentRunAccountOpened
{
    public function __construct(public readonly string $accountId, public readonly string $name)
    {
    }
}

#[EventSourcingAggregate]
final class ConcurrentRunAccount
{
    use WithAggregateVersioning;

    #[Identifier]
    private string $accountId;

    #[CommandHandler]
    public static function open(OpenConcurrentRunAccount $command): array
    {
        return [new ConcurrentRunAccountOpened($command->accountId, $command->name)];
    }

    #[Apply]
    public function applyOpened(ConcurrentRunAccountOpened $event): void
    {
        $this->accountId = $event->accountId;
    }
}
