<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Recoverability;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\PDO\MySQL\Driver as MySQLDriver;
use Doctrine\DBAL\Driver\PDO\PgSQL\Driver as PgSQLDriver;
use Doctrine\DBAL\Driver\PDO\SQLite\Driver as SQLiteDriver;
use Doctrine\DBAL\Driver\Statement;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\PartitionDeadLetter;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Test\LicenceTesting;
use RuntimeException;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class PartitionDeadLetterStoreFailureTest extends DbalMessagingTestCase
{
    public static int $failingWritesOfAPartitionKey = 0;
    private object $orders;

    public function tearDown(): void
    {
        self::$failingWritesOfAPartitionKey = 0;
        parent::tearDown();
    }

    public function test_a_failed_message_that_could_not_be_stored_under_its_partition_key_is_not_left_in_the_dead_letter_outside_the_key(): void
    {
        $ecotone = $this->bootstrap();

        self::$failingWritesOfAPartitionKey = 1;
        $this->place($ecotone, 'broken', messageId: 'm-1');
        $this->consume($ecotone);

        self::assertSame(1, $ecotone->getGateway(DeadLetterGateway::class)->count());
        self::assertSame([['partitionStore.place.endpoint', '1', 'm-1']], $this->held($ecotone));
    }

    public function test_a_failed_message_delivered_again_after_its_store_kept_failing_is_in_the_dead_letter_once_and_handled_first_when_its_partition_key_is_replayed(): void
    {
        $ecotone = $this->bootstrap();

        self::$failingWritesOfAPartitionKey = 4;
        $this->place($ecotone, 'broken', messageId: 'm-1');
        $this->consume($ecotone);
        $this->place($ecotone, 'coffee', messageId: 'm-2');
        $this->consume($ecotone);

        self::assertSame(2, $ecotone->getGateway(DeadLetterGateway::class)->count());
        self::assertSame([['partitionStore.place.endpoint', '1', 'm-1'], ['partitionStore.place.endpoint', '1', 'm-2']], $this->held($ecotone));

        $this->orders->broken = [];
        $ecotone->getGateway(DeadLetterGateway::class)->replay('m-2');
        $this->consume($ecotone);

        self::assertSame(['broken', 'coffee'], $this->orders->placed);
        self::assertSame(0, $ecotone->getGateway(DeadLetterGateway::class)->count());
    }

    private function place(FlowTestSupport $ecotone, string $item, string $messageId): void
    {
        $ecotone->sendCommandWithRouting('partitionStore.place', $item, metadata: ['orderId' => '1', MessageHeaders::MESSAGE_ID => $messageId]);
    }

    private function consume(FlowTestSupport $ecotone): void
    {
        $ecotone->run('partitionStore.orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));
    }

    /**
     * @return list<array{string, string, string}>
     */
    private function held(FlowTestSupport $ecotone): array
    {
        $held = [];
        foreach ($ecotone->getGateway(DeadLetterGateway::class)->list(100, 0) as $errorContext) {
            if ($errorContext->partitionKey !== null) {
                $held[$errorContext->partitionPosition] = [$errorContext->partitionTrackingName, $errorContext->partitionKey, $errorContext->getMessageId()];
            }
        }
        ksort($held);

        return array_values($held);
    }

    private function bootstrap(): FlowTestSupport
    {
        $this->orders = new class () {
            public array $placed = [];
            public array $broken = ['broken'];

            #[PartitionDeadLetter(partitionHeaderName: 'orderId', trackingName: 'partitionStore.place.endpoint')]
            #[Asynchronous('partitionStore.orders')]
            #[CommandHandler('partitionStore.place', endpointId: 'partitionStore.place.endpoint')]
            public function place(string $item): void
            {
                if (in_array($item, $this->broken, true)) {
                    throw new RuntimeException("cannot place {$item}");
                }

                $this->placed[] = $item;
            }
        };
        $database = $this->databaseFailingWritesOfAPartitionKey();

        return $this->bootstrapFlowTesting(
            classesToResolve: [$this->orders::class],
            containerOrAvailableServices: [$this->orders, DbalConnectionFactory::class => new DbalConnectionFactory(['dsn' => getenv('DATABASE_DSN'), 'connection' => ['driverClass' => $database::class]])],
            extensionObjects: [
                ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel('dbal_dead_letter'),
                DbalConfiguration::createWithDefaults()->withDeadLetter(true),
                DbalBackedMessageChannelBuilder::create('partitionStore.orders'),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        )->setupDatabaseTables();
    }

    private function databaseFailingWritesOfAPartitionKey(): Driver
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
                        if (preg_match('/^\s*(INSERT|UPDATE)\b.*\bpartition_key\b/is', $sql) === 1 && PartitionDeadLetterStoreFailureTest::$failingWritesOfAPartitionKey-- > 0) {
                            throw new RuntimeException('the database refused the write');
                        }

                        return parent::prepare($sql);
                    }
                };
            }
        };
    }
}
