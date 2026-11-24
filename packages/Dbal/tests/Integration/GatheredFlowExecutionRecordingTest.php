<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\PDO\MySQL\Driver as MySQLDriver;
use Doctrine\DBAL\Driver\PDO\PgSQL\Driver as PgSQLDriver;
use Doctrine\DBAL\Driver\PDO\SQLite\Driver as SQLiteDriver;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Gathered;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\Splitter;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\GatheredParts;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Api\Tracing\TracingConfiguration;
use Ecotone\Api\Tracing\TracingLevel;
use Ecotone\Test\LicenceTesting;
use RuntimeException;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Throwable;

/**
 * licence Apache-2.0
 * @internal
 */
final class GatheredFlowExecutionRecordingTest extends DbalMessagingTestCase
{
    public static bool $databaseStopsAnswering = false;

    public function test_a_gathered_flow_over_the_dbal_group_store_is_recorded_part_by_part_and_gathers_as_without_recording(): void
    {
        $gathered = [];
        $lines = [];
        foreach ([false, true] as $recording) {
            [$gathered[], $lines] = $this->gatheredWith($recording);
        }

        self::assertSame($gathered[0], $gathered[1]);
        self::assertSame(
            [
                '↳ outcome: part stored, 1 of 2 in, group waits',
                '↳ outcome: part stored, 2 of 2 in, group released',
            ],
            array_map(fn (int $position): string => $lines[$position + 1], array_keys(array_filter($lines, fn (string $line): bool => preg_match('/^record part \d of 2 in DbalGatheredGroupStore$/', $line) === 1))),
        );
        self::assertContains('↳ outcome: GatheredParts, 2 of 2 parts, original payload array', $lines);
    }

    /**
     * @return array{0: list<array<int, mixed>>, 1: list<string>}
     */
    private function gatheredWith(bool $recording): array
    {
        [$ecotone, $handler] = $this->application($recording);

        $ecotone->sendCommandWithRouting('split', [10, 20]);
        $ecotone->run('workers')->run('summaries');

        return [$handler->gathered, $recording ? array_map(trim(...), $ecotone->popExecutionRecording()->lines) : []];
    }

    public function test_a_group_store_call_that_fails_fails_the_same_and_leaves_the_next_process_recorded_whole(): void
    {
        $outcomes = [];
        foreach ([false, true] as $recording) {
            $database = $this->databaseThatCanStopAnswering();
            [$ecotone] = $this->application($recording, new DbalConnectionFactory(['dsn' => getenv('DATABASE_DSN'), 'connection' => ['driverClass' => $database::class]]));
            $ecotone->sendCommandWithRouting('split', [10, 20]);
            $ecotone->run('workers');
            self::$databaseStopsAnswering = true;
            try {
                $ecotone->run('summaries');
                $failure = null;
            } catch (Throwable $thrown) {
                $failure = $thrown::class;
            }
            self::$databaseStopsAnswering = false;
            $ecotone->sendCommandWithRouting('ping');
            $outcomes[] = $failure;
            $lines = $recording ? $ecotone->popExecutionRecording()->lines : [];
            $failingStep = $recording ? array_map(trim(...), array_slice($lines, (int) array_search(true, array_map(fn (string $line): bool => str_contains($line, '↳ failure: throws RuntimeException'), $lines), true) - 1, 2)) : [];
        }
        [$alone] = $this->application(true, new DbalConnectionFactory(getenv('DATABASE_DSN')));
        $alone->sendCommandWithRouting('ping');
        $ping = fn (array $of): array => array_map(fn (string $line): string => (string) preg_replace(['/^process \d+: /', '/\{generated#\d+\}/'], ['process: ', '{generated}'], $line), array_slice($of, (int) array_search('process 1: send command ping, class@anonymous::ping', $of, true)));

        self::assertSame($outcomes[0], $outcomes[1]);
        self::assertNotNull($outcomes[0]);
        self::assertSame(['record part 1 of 2 in DbalGatheredGroupStore', '↳ failure: throws RuntimeException'], $failingStep);
        self::assertSame(
            $ping($alone->popExecutionRecording()->lines),
            array_map(fn (string $line): string => (string) preg_replace(['/^process \d+: /', '/\{generated#\d+\}/'], ['process: ', '{generated}'], $line), array_slice($lines, (int) array_key_last(array_filter($lines, fn (string $line): bool => str_starts_with($line, 'process ') && str_contains($line, 'send command ping'))))),
        );
    }

    /**
     * @return array{0: FlowTestSupport, 1: object}
     */
    private function application(bool $recording, ?DbalConnectionFactory $connectionFactory = null): array
    {
        $handler = new class () {
            public array $gathered = [];

            #[CommandHandler('ping')]
            public function ping(): void
            {
            }

            #[CommandHandler('split', outputChannelName: 'parts')]
            public function start(array $parts): array
            {
                return $parts;
            }

            #[Splitter('parts', endpointId: 'split.parts', outputChannelName: 'work', gatherChannelName: 'summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[Asynchronous('workers')]
            #[InternalHandler('work', endpointId: 'work.handler')]
            public function work(int $part): int
            {
                return $part;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('summary', endpointId: 'summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                $this->gathered[] = $parts->results;
            }
        };
        $tracing = TracingConfiguration::createWithDefaults()->withSpanRecorder(InMemorySpanRecorder::class);
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [$handler, DbalConnectionFactory::class => $connectionFactory ?? new DbalConnectionFactory(getenv('DATABASE_DSN')), InMemorySpanRecorder::class => new InMemorySpanRecorder()],
            extensionObjects: [
                SimpleMessageChannelBuilder::createQueueChannel('workers'),
                SimpleMessageChannelBuilder::createQueueChannel('summaries'),
                $recording ? $tracing->withLevel(TracingLevel::Framework) : $tracing,
                ...($connectionFactory === null ? [] : [DbalConfiguration::createWithDefaults()->withDeduplication(false)->withTransactionOnAsynchronousEndpoints(false)->withTransactionOnCommandBus(false)]),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ModulePackageList::TRACING_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );

        return [$ecotone, $handler];
    }

    private function databaseThatCanStopAnswering(): Driver
    {
        return new class () extends AbstractDriverMiddleware {
            public function __construct()
            {
                $dsn = (string) getenv('DATABASE_DSN');
                parent::__construct(match (true) {
                    str_starts_with($dsn, 'pgsql') => new PgSQLDriver(),
                    str_starts_with($dsn, 'mysql') => new MySQLDriver(),
                    default => new SQLiteDriver(),
                });
            }

            public function connect(array $params): DriverConnection
            {
                return new class (parent::connect($params)) extends AbstractConnectionMiddleware {
                    public function prepare(string $sql): Statement
                    {
                        self::refuseWhileTheDatabaseDoesNotAnswer();

                        return parent::prepare($sql);
                    }

                    public function query(string $sql): Result
                    {
                        self::refuseWhileTheDatabaseDoesNotAnswer();

                        return parent::query($sql);
                    }

                    public function exec(string $sql): int|string
                    {
                        self::refuseWhileTheDatabaseDoesNotAnswer();

                        return parent::exec($sql);
                    }

                    private static function refuseWhileTheDatabaseDoesNotAnswer(): void
                    {
                        if (GatheredFlowExecutionRecordingTest::$databaseStopsAnswering) {
                            throw new RuntimeException('the database does not answer');
                        }
                    }
                };
            }
        };
    }
}
