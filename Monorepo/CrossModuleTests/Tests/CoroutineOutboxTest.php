<?php

declare(strict_types=1);

namespace Monorepo\CrossModuleTests\Tests;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\OutboxForwardingMessageChannel;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Redis\RedisBackedMessageChannelBuilder;
use Ecotone\Api\Redis\RedisConnectionFactory;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Test\CoroutineTesting;
use Ecotone\Test\LicenceTesting;
use Enqueue\Redis\RedisDestination;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

/**
 * licence Apache-2.0
 * @internal
 */
#[RequiresMethod(Coroutine::class, 'create')]
final class CoroutineOutboxTest extends TestCase
{
    public function test_messages_stored_in_the_outbox_at_once_are_forwarded_and_handled_once_by_consumer_coroutines_of_the_target_channel(): void
    {
        $ecotone = $this->bootstrapOrdersBehindAnOutbox();

        CoroutineTesting::runAtOnce(...array_map(
            static fn (int $publisher): callable => static function () use ($ecotone, $publisher): void {
                foreach ([1, 2, 3] as $order) {
                    $ecotone->sendCommandWithRouting('order.place', "order-{$publisher}-{$order}", metadata: ['tenant' => "tenant-{$publisher}"]);
                }
            },
            [1, 2, 3, 4],
        ));
        $ecotone->run('coroutine_outbox', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
        $ecotone->run('coroutine_order_processing', ExecutionPollingMetadata::createWithFinishWhenNoMessages()->withCoroutines(3));

        $expectedOrders = [];
        foreach ([1, 2, 3, 4] as $publisher) {
            foreach ([1, 2, 3] as $order) {
                $expectedOrders["order-{$publisher}-{$order}"] = ["tenant-{$publisher}"];
            }
        }
        $this->assertSame($expectedOrders, $ecotone->sendQueryWithRouting('order.placed'));
        $this->assertSame(3, $ecotone->sendQueryWithRouting('order.mostPlacedAtOnce'));
    }

    public function test_consumer_forwarding_the_outbox_is_refused_on_coroutines(): void
    {
        $ecotone = $this->bootstrapOrdersBehindAnOutbox();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'Endpoint `coroutine_outbox` is set to run on 3 coroutines, but it runs on a schedule instead of consuming a channel, so every coroutine would run each scheduled execution again. '
            . 'Leave out the `coroutines` option of ecotone:run, ExecutionPollingMetadata::withCoroutines() and PollingMetadata::withCoroutines() for this endpoint. '
            . "To do its work on coroutines, let it send messages to an #[Asynchronous] handler and run that channel's consumer on coroutines."
        );

        $ecotone->run('coroutine_outbox', ExecutionPollingMetadata::createWithFinishWhenNoMessages()->withCoroutines(3));
    }

    private function bootstrapOrdersBehindAnOutbox(): FlowTestSupport
    {
        $databaseDsn = (string) getenv('DATABASE_MYSQL');
        $redisDsn = getenv('REDIS_DSN') ?: 'redis://localhost:6379';
        $connection = (new DbalConnectionFactory($databaseDsn))->createContext()->getDbalConnection();
        foreach ($connection->createSchemaManager()->listTableNames() as $tableName) {
            $connection->executeStatement('DROP TABLE ' . $connection->getDatabasePlatform()->quoteIdentifier(trim($tableName, '"`')));
        }
        $connection->close();
        (new RedisConnectionFactory($redisDsn))->createContext()->purgeQueue(new RedisDestination('coroutine_order_processing'));
        $orders = new class () {
            private array $placed = [];
            private int $placedAtOnce = 0;
            private int $mostPlacedAtOnce = 0;

            #[Asynchronous('orders')]
            #[CommandHandler('order.place', endpointId: 'order.place.endpoint')]
            public function place(string $orderId, #[Header('tenant')] string $tenant): void
            {
                $this->placedAtOnce++;
                $this->mostPlacedAtOnce = max($this->mostPlacedAtOnce, $this->placedAtOnce);
                usleep(20_000);
                $this->placed[$orderId][] = $tenant;
                $this->placedAtOnce--;
            }

            #[QueryHandler('order.placed')]
            public function placed(): array
            {
                ksort($this->placed);

                return $this->placed;
            }

            #[QueryHandler('order.mostPlacedAtOnce')]
            public function mostPlacedAtOnce(): int
            {
                return $this->mostPlacedAtOnce;
            }
        };
        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$orders::class],
            containerOrAvailableServices: [
                $orders,
                DbalConnectionFactory::class => new DbalConnectionFactory(['dsn' => $databaseDsn, 'connection_pool_size' => 10]),
                RedisConnectionFactory::class => new RedisConnectionFactory($redisDsn),
            ],
            extensionObjects: [
                OutboxForwardingMessageChannel::create(referenceName: 'orders', sourceChannelName: DbalBackedMessageChannelBuilder::create('coroutine_outbox'), targetChannelName: 'coroutine_order_processing'),
                RedisBackedMessageChannelBuilder::create('coroutine_order_processing')->withReceiveTimeout(50),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ModulePackageList::REDIS_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
