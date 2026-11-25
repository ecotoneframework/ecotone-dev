<?php

declare(strict_types=1);

namespace Test\Ecotone\Redis\Integration;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Redis\RedisBackedMessageChannelBuilder;
use Ecotone\Api\Redis\RedisConnectionFactory;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Test\CoroutineTesting;
use Enqueue\Redis\PRedis;
use Enqueue\Redis\RedisDestination;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresMethod;
use Swoole\Coroutine;
use Test\Ecotone\Redis\ConnectionTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
#[RequiresMethod(Coroutine::class, 'create')]
final class CoroutineExecutionsTest extends ConnectionTestCase
{
    public static function redisClients(): iterable
    {
        yield 'predis' => ['redis:'];
        yield 'phpredis' => ['redis+phpredis:'];
    }

    #[DataProvider('redisClients')]
    public function test_coroutines_publishing_and_consuming_at_once_handle_every_message_once_with_its_own_headers(string $scheme): void
    {
        $ecotone = $this->bootstrapOrders($scheme);

        CoroutineTesting::runAtOnce(
            ...$this->publishersOfOrders($ecotone, publishers: 4, ordersOfEachPublisher: 25),
            ...$this->consumersOf($ecotone, 'coroutine_orders', consumers: 2),
            ...$this->consumersOf($ecotone, 'coroutine_shipments', consumers: 2),
        );
        $ecotone->run('coroutine_orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
        $ecotone->run('coroutine_shipments', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        $this->assertEquals($this->ordersWithTheirTenants(publishers: 4, ordersOfEachPublisher: 25), $ecotone->sendQueryWithRouting('order.shipped'));
    }

    #[DataProvider('redisClients')]
    public function test_consumers_on_coroutines_handle_every_message_once_with_its_own_headers(string $scheme): void
    {
        $ecotone = $this->bootstrapOrders($scheme);
        CoroutineTesting::runAtOnce(...$this->publishersOfOrders($ecotone, publishers: 4, ordersOfEachPublisher: 25));

        $ecotone->run('coroutine_orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages()->withCoroutines(3));
        $ecotone->run('coroutine_shipments', ExecutionPollingMetadata::createWithFinishWhenNoMessages()->withCoroutines(3));

        $this->assertEquals($this->ordersWithTheirTenants(publishers: 4, ordersOfEachPublisher: 25), $ecotone->sendQueryWithRouting('order.shipped'));
    }

    public function test_client_the_application_connected_itself_is_refused_inside_a_coroutine(): void
    {
        $connectionFactory = new RedisConnectionFactory(new PRedis(['scheme' => 'redis', 'host' => 'redis', 'port' => 6379, 'password' => null, 'database' => null, 'path' => null, 'async' => false, 'persistent' => false, 'timeout' => 5.0, 'read_write_timeout' => null, 'ssl' => null, 'predis_options' => null]));

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'RedisConnectionFactory was given a Redis client the application connected itself, and is used inside a Swoole or OpenSwoole coroutine. '
            . 'That client is one connection for the whole process, and two coroutines reading from one connection at once stop the process. '
            . "Pass the connection settings instead, `new RedisConnectionFactory('redis://host:6379')` or `new RedisConnectionFactory(['dsn' => 'redis+phpredis://host:6379'])`, so every coroutine opens a connection of its own, "
            . 'or handle one message at a time: set `enable_coroutine` to false on the server and run consumers without the `coroutines` option.'
        );

        CoroutineTesting::runAtOnce(static fn () => $connectionFactory->createContext()->getRedis());
    }

    /**
     * @return callable[]
     */
    private function publishersOfOrders(FlowTestSupport $ecotone, int $publishers, int $ordersOfEachPublisher): array
    {
        $coroutines = [];
        for ($publisher = 1; $publisher <= $publishers; $publisher++) {
            $coroutines[] = static function () use ($ecotone, $publisher, $ordersOfEachPublisher): void {
                for ($order = 1; $order <= $ordersOfEachPublisher; $order++) {
                    $ecotone->sendCommandWithRouting('order.register', ['orderId' => "order-{$publisher}-{$order}"], metadata: ['tenant' => "tenant-{$publisher}-{$order}"]);
                }
            };
        }

        return $coroutines;
    }

    /**
     * @return callable[]
     */
    private function consumersOf(FlowTestSupport $ecotone, string $channelName, int $consumers): array
    {
        return array_fill(0, $consumers, static fn () => $ecotone->run($channelName, ExecutionPollingMetadata::createWithDefaults()->withExecutionTimeLimitInMilliseconds(1500)));
    }

    /**
     * @return array<string, string[]>
     */
    private function ordersWithTheirTenants(int $publishers, int $ordersOfEachPublisher): array
    {
        $orders = [];
        for ($publisher = 1; $publisher <= $publishers; $publisher++) {
            for ($order = 1; $order <= $ordersOfEachPublisher; $order++) {
                $orders["order-{$publisher}-{$order}"] = ["tenant-{$publisher}-{$order}"];
            }
        }

        return $orders;
    }

    private function bootstrapOrders(string $scheme): FlowTestSupport
    {
        $orders = new class () {
            private array $shipped = [];

            #[CommandHandler('order.register')]
            public function register(array $command, CommandBus $commandBus): void
            {
                $commandBus->sendWithRouting('order.place', $command['orderId']);
                usleep(2_000);
            }

            #[Asynchronous('coroutine_orders')]
            #[CommandHandler('order.place', endpointId: 'order.place.endpoint')]
            public function place(string $orderId, CommandBus $commandBus): void
            {
                usleep(1_000);
                $commandBus->sendWithRouting('order.ship', $orderId);
            }

            #[Asynchronous('coroutine_shipments')]
            #[CommandHandler('order.ship', endpointId: 'order.ship.endpoint')]
            public function ship(string $orderId, #[Header('tenant')] string $tenant): void
            {
                $this->shipped[$orderId][] = $tenant;
            }

            #[QueryHandler('order.shipped')]
            public function shipped(): array
            {
                return $this->shipped;
            }
        };
        $dsn = preg_replace('/^redis:/', $scheme, getenv('REDIS_DSN') ?: 'redis://localhost:6379');
        $connectionFactory = new RedisConnectionFactory($dsn);
        foreach (['coroutine_orders', 'coroutine_shipments'] as $queueName) {
            $connectionFactory->createContext()->purgeQueue(new RedisDestination($queueName));
        }

        return EcotoneLite::bootstrapFlowTesting(
            [$orders::class],
            [$orders, RedisConnectionFactory::class => new RedisConnectionFactory($dsn)],
            extensionObjects: [
                RedisBackedMessageChannelBuilder::create('coroutine_orders')->withReceiveTimeout(50),
                RedisBackedMessageChannelBuilder::create('coroutine_shipments')->withReceiveTimeout(50),
            ],
            enabledModules: [ModulePackageList::REDIS_PACKAGE],
        );
    }
}
