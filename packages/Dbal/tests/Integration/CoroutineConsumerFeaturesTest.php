<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use DateTimeImmutable;
use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Deduplicated;
use Ecotone\Api\Attribute\Delayed;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Attribute\Saga;
use Ecotone\Api\Attribute\Version;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\DbalDeadLetterBuilder;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Scheduling\Duration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresMethod;
use Swoole\Coroutine;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
#[RequiresMethod(Coroutine::class, 'create')]
final class CoroutineConsumerFeaturesTest extends DbalMessagingTestCase
{
    public static function databases(): iterable
    {
        yield 'mysql' => [(string) getenv('DATABASE_MYSQL')];
        yield 'mariadb' => [(string) getenv('DATABASE_MARIADB')];
        if (extension_loaded('swoole')) {
            yield 'postgresql' => [str_starts_with((string) getenv('DATABASE_DSN'), 'pgsql') ? (string) getenv('DATABASE_DSN') : (string) getenv('SECONDARY_DATABASE_DSN')];
        }
    }

    #[DataProvider('databases')]
    public function test_messages_with_one_deduplication_key_handled_at_once_by_consumer_coroutines_are_handled_once(string $databaseDsn): void
    {
        $orders = new class () {
            private array $placed = [];

            #[Deduplicated('orderNumber')]
            #[Asynchronous('orders')]
            #[CommandHandler('order.place', endpointId: 'order.place.endpoint')]
            public function place(string $orderNumber): void
            {
                usleep(50_000);
                $this->placed[] = $orderNumber;
            }

            #[QueryHandler('order.placed')]
            public function placed(): array
            {
                return $this->placed;
            }
        };
        $ecotone = $this->bootstrapOrders(orders: $orders, databaseDsn: $databaseDsn, initialTime: new DateTimeImmutable('2026-11-25 12:00:00 UTC'));
        foreach (['order-1', 'order-1', 'order-1', 'order-2'] as $orderNumber) {
            $ecotone->sendCommandWithRouting('order.place', $orderNumber, metadata: ['orderNumber' => $orderNumber]);
        }

        $ecotone->run('orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false)->withCoroutines(4));

        $placed = $ecotone->sendQueryWithRouting('order.placed');
        sort($placed);
        $this->assertSame(['order-1', 'order-2'], $placed);
        $this->assertSame(0, $ecotone->getGateway(DeadLetterGateway::class)->count());
    }

    #[DataProvider('databases')]
    public function test_delayed_messages_are_handled_at_once_by_consumer_coroutines_only_after_their_delay(string $databaseDsn): void
    {
        $orders = new class () {
            private array $placed = [];
            private int $placedAtOnce = 0;
            private int $mostPlacedAtOnce = 0;

            #[Delayed(seconds: 60)]
            #[Asynchronous('orders')]
            #[CommandHandler('order.place', endpointId: 'order.place.endpoint')]
            public function place(string $orderNumber): void
            {
                $this->placedAtOnce++;
                $this->mostPlacedAtOnce = max($this->mostPlacedAtOnce, $this->placedAtOnce);
                usleep(50_000);
                $this->placed[] = $orderNumber;
                $this->placedAtOnce--;
            }

            #[QueryHandler('order.placed')]
            public function placed(): array
            {
                return $this->placed;
            }

            #[QueryHandler('order.mostPlacedAtOnce')]
            public function mostPlacedAtOnce(): int
            {
                return $this->mostPlacedAtOnce;
            }
        };
        $ecotone = $this->bootstrapOrders(orders: $orders, databaseDsn: $databaseDsn, initialTime: new DateTimeImmutable('2026-11-25 12:00:00 UTC'));
        foreach (['order-1', 'order-2', 'order-3'] as $orderNumber) {
            $ecotone->sendCommandWithRouting('order.place', $orderNumber);
        }

        $ecotone->run('orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages()->withCoroutines(3));
        $placedBeforeTheDelay = $ecotone->sendQueryWithRouting('order.placed');
        $ecotone->advanceTimeBy(Duration::seconds(60));
        $ecotone->run('orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages()->withCoroutines(3));

        $placed = $ecotone->sendQueryWithRouting('order.placed');
        sort($placed);
        $this->assertSame([], $placedBeforeTheDelay);
        $this->assertSame(['order-1', 'order-2', 'order-3'], $placed);
        $this->assertSame(3, $ecotone->sendQueryWithRouting('order.mostPlacedAtOnce'));
    }

    public static function modelsOnDatabases(): iterable
    {
        foreach (self::databases() as $database => [$databaseDsn]) {
            yield "state-stored aggregate on {$database}" => [$databaseDsn, 'coroutine_wallet'];
            yield "saga on {$database}" => [$databaseDsn, 'coroutine_wallet_audit'];
        }
    }

    #[DataProvider('modelsOnDatabases')]
    public function test_commands_of_one_stored_model_handled_at_once_by_consumer_coroutines_are_all_applied_after_the_lost_writes_are_replayed(string $databaseDsn, string $model): void
    {
        $ecotone = $this->bootstrapOrders(orders: new class () {
        }, databaseDsn: $databaseDsn, initialTime: new DateTimeImmutable('2026-11-25 12:00:00 UTC'));
        $ecotone->sendCommandWithRouting("{$model}.open", 'wallet-1');
        for ($deposit = 1; $deposit <= 6; $deposit++) {
            $ecotone->sendCommandWithRouting("{$model}.deposit", $deposit, metadata: ['aggregate.id' => 'wallet-1']);
        }

        $ecotone->run('orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false)->withCoroutines(3));
        $deadLetters = $ecotone->getGateway(DeadLetterGateway::class);
        while ($deadLetters->count() > 0) {
            $deadLetters->replayAll();
            $ecotone->run('orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));
        }

        $deposits = $ecotone->sendQueryWithRouting("{$model}.deposits", metadata: ['aggregate.id' => 'wallet-1']);
        sort($deposits);
        $this->assertSame([1, 2, 3, 4, 5, 6], $deposits);
    }

    private function bootstrapOrders(object $orders, string $databaseDsn, DateTimeImmutable $initialTime): FlowTestSupport
    {
        $connection = (new DbalConnectionFactory($databaseDsn))->createContext()->getDbalConnection();
        foreach ($connection->createSchemaManager()->listTableNames() as $tableName) {
            $connection->executeStatement('DROP TABLE ' . $connection->getDatabasePlatform()->quoteIdentifier(trim($tableName, '"`')));
        }
        $connection->close();

        return $this->bootstrapFlowTesting(
            classesToResolve: [$orders::class, CoroutineWallet::class, CoroutineWalletAudit::class],
            containerOrAvailableServices: [$orders, DbalConnectionFactory::class => new DbalConnectionFactory(['dsn' => $databaseDsn, 'connection_pool_size' => 10])],
            extensionObjects: [
                DbalConfiguration::createWithDefaults()->withDocumentStore(enableDocumentStoreStateStoredRepository: true),
                DbalBackedMessageChannelBuilder::create('orders'),
                PollingMetadata::create('orders')->withErrorChannelName(DbalDeadLetterBuilder::STORE_CHANNEL),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ModulePackageList::JMS_CONVERTER_PACKAGE],
            addInMemoryStateStoredRepository: false,
            initialTime: $initialTime,
        );
    }
}

#[Aggregate]
final class CoroutineWallet
{
    /**
     * @param int[] $deposits
     */
    private function __construct(#[Identifier] private string $walletId, private array $deposits, #[Version] private int $version)
    {
    }

    #[CommandHandler('coroutine_wallet.open')]
    public static function open(string $walletId): self
    {
        return new self(walletId: $walletId, deposits: [], version: 0);
    }

    #[Asynchronous('orders')]
    #[CommandHandler('coroutine_wallet.deposit', endpointId: 'coroutine_wallet.deposit.endpoint')]
    public function deposit(int $amount): void
    {
        usleep(20_000);
        $this->deposits[] = $amount;
    }

    #[QueryHandler('coroutine_wallet.deposits')]
    public function deposits(): array
    {
        return $this->deposits;
    }
}

#[Saga]
final class CoroutineWalletAudit
{
    /**
     * @param int[] $deposits
     */
    private function __construct(#[Identifier] private string $walletId, private array $deposits, #[Version] private int $version)
    {
    }

    #[CommandHandler('coroutine_wallet_audit.open')]
    public static function open(string $walletId): self
    {
        return new self(walletId: $walletId, deposits: [], version: 0);
    }

    #[Asynchronous('orders')]
    #[CommandHandler('coroutine_wallet_audit.deposit', endpointId: 'coroutine_wallet_audit.deposit.endpoint')]
    public function deposit(int $amount): void
    {
        usleep(20_000);
        $this->deposits[] = $amount;
    }

    #[QueryHandler('coroutine_wallet_audit.deposits')]
    public function deposits(): array
    {
        return $this->deposits;
    }
}
