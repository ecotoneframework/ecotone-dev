<?php

declare(strict_types=1);

namespace Test\Ecotone\Redis\Integration;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Distributed;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\InstantRetryConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Redis\RedisBackedMessageChannelBuilder;
use Ecotone\Api\Redis\RedisConnectionFactory;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Test\CoroutineTesting;
use Ecotone\Test\LicenceTesting;
use Enqueue\Redis\PRedis;
use Enqueue\Redis\RedisDestination;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresMethod;
use RuntimeException;
use Swoole\Coroutine;
use Test\Ecotone\Redis\ConnectionTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
#[RequiresMethod(Coroutine::class, 'create')]
final class CoroutineExecutionsTest extends ConnectionTestCase
{
    private const CLIENT_OF_THE_APPLICATION_REFUSED = 'RedisConnectionFactory was given a Redis client the application connected itself, and is used inside a Swoole or OpenSwoole coroutine. '
        . 'That client is one connection for the whole process, and two coroutines reading from one connection at once stop the process. '
        . "Pass the connection settings instead, `new RedisConnectionFactory('redis://host:6379')` or `new RedisConnectionFactory(['dsn' => 'redis+phpredis://host:6379'])`, so every coroutine opens a connection of its own, "
        . 'or handle one message at a time: set `enable_coroutine` to false on the server and run consumers without the `coroutines` option.';

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

    #[DataProvider('redisClients')]
    public function test_consumer_coroutine_whose_handler_fails_sends_only_its_own_message_to_the_error_channel(string $scheme): void
    {
        $orders = new class () {
            private array $placed = [];
            private int $failed = 0;

            #[Asynchronous('coroutine_orders')]
            #[CommandHandler('order.place', endpointId: 'order.place.endpoint')]
            public function place(string $orderId): void
            {
                usleep(20_000);
                if ($orderId === 'refused') {
                    throw new RuntimeException('Order was refused');
                }
                $this->placed[] = $orderId;
            }

            #[InternalHandler('failed_orders')]
            public function whenFailed(Message $failure): void
            {
                $this->failed++;
            }

            #[QueryHandler('order.placed')]
            public function placed(): array
            {
                sort($this->placed);

                return $this->placed;
            }

            #[QueryHandler('order.failed')]
            public function failed(): int
            {
                return $this->failed;
            }
        };
        $ecotone = $this->bootstrap(
            handlers: $orders,
            connectionFactory: new RedisConnectionFactory($this->dsnOf($scheme)),
            extensionObjects: [ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel('failed_orders'), InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false)],
        );
        foreach (['order-1', 'order-2', 'refused', 'order-3', 'order-4', 'order-5'] as $orderId) {
            $ecotone->sendCommandWithRouting('order.place', $orderId);
        }

        $ecotone->run('coroutine_orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false)->withCoroutines(3));

        $this->assertSame(['order-1', 'order-2', 'order-3', 'order-4', 'order-5'], $ecotone->sendQueryWithRouting('order.placed'));
        $this->assertSame(1, $ecotone->sendQueryWithRouting('order.failed'));
    }

    public function test_distributed_commands_and_events_published_at_once_are_handled_once_by_consumer_coroutines_of_the_receiving_service(): void
    {
        (new RedisConnectionFactory($this->dsnOf('redis:')))->createContext()->purgeQueue(new RedisDestination('coroutine_tickets'));
        $tickets = new class () {
            private array $created = [];
            private array $billingChanges = [];
            private int $createdAtOnce = 0;
            private int $mostCreatedAtOnce = 0;

            #[Distributed]
            #[CommandHandler('ticket.create')]
            public function create(string $description, #[Header('tenant')] string $tenant): void
            {
                $this->createdAtOnce++;
                $this->mostCreatedAtOnce = max($this->mostCreatedAtOnce, $this->createdAtOnce);
                usleep(20_000);
                $this->created[$description][] = $tenant;
                $this->createdAtOnce--;
            }

            #[Distributed]
            #[EventHandler('user.billing_changed')]
            public function whenBillingChanged(string $user): void
            {
                $this->billingChanges[] = $user;
            }

            #[QueryHandler('ticket.created')]
            public function created(): array
            {
                ksort($this->created);

                return $this->created;
            }

            #[QueryHandler('ticket.billingChanges')]
            public function billingChanges(): array
            {
                sort($this->billingChanges);

                return $this->billingChanges;
            }

            #[QueryHandler('ticket.mostCreatedAtOnce')]
            public function mostCreatedAtOnce(): int
            {
                return $this->mostCreatedAtOnce;
            }
        };
        $userService = EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [RedisConnectionFactory::class => new RedisConnectionFactory($this->dsnOf('redis:'))],
            extensionObjects: [
                RedisBackedMessageChannelBuilder::create('coroutine_tickets')->withReceiveTimeout(50),
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ticket_service', channelName: 'coroutine_tickets')->withEventMapping(channelName: 'coroutine_tickets', subscriptionKeys: ['user.*']),
                ServiceConfiguration::createWithDefaults()->withServiceName('user_service'),
            ],
            enabledModules: [ModulePackageList::REDIS_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $ticketService = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$tickets::class],
            containerOrAvailableServices: [$tickets, RedisConnectionFactory::class => new RedisConnectionFactory($this->dsnOf('redis:'))],
            extensionObjects: [
                RedisBackedMessageChannelBuilder::create('coroutine_tickets')->withReceiveTimeout(50),
                ServiceConfiguration::createWithDefaults()->withServiceName('ticket_service'),
            ],
            enabledModules: [ModulePackageList::REDIS_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        CoroutineTesting::runAtOnce(...array_map(
            static fn (int $user): callable => static function () use ($userService, $user): void {
                foreach ([1, 2, 3] as $ticket) {
                    $userService->getDistributedBus()->sendCommand('ticket_service', 'ticket.create', "ticket-{$user}-{$ticket}", metadata: ['tenant' => "tenant-{$user}"]);
                }
                $userService->getDistributedBus()->publishEvent('user.billing_changed', "user-{$user}");
            },
            [1, 2, 3, 4],
        ));
        $ticketService->run('coroutine_tickets', ExecutionPollingMetadata::createWithFinishWhenNoMessages()->withCoroutines(3));

        $expectedTickets = [];
        foreach ([1, 2, 3, 4] as $user) {
            foreach ([1, 2, 3] as $ticket) {
                $expectedTickets["ticket-{$user}-{$ticket}"] = ["tenant-{$user}"];
            }
        }
        $this->assertSame($expectedTickets, $ticketService->sendQueryWithRouting('ticket.created'));
        $this->assertSame(['user-1', 'user-2', 'user-3', 'user-4'], $ticketService->sendQueryWithRouting('ticket.billingChanges'));
        $this->assertSame(3, $ticketService->sendQueryWithRouting('ticket.mostCreatedAtOnce'));
    }

    public function test_publishing_inside_a_coroutine_with_a_client_the_application_connected_itself_is_refused(): void
    {
        $ecotone = $this->bootstrap(handlers: $this->orders(), connectionFactory: new RedisConnectionFactory($this->clientTheApplicationConnectedItself()));

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(self::CLIENT_OF_THE_APPLICATION_REFUSED);

        CoroutineTesting::runAtOnce(static fn () => $ecotone->sendCommandWithRouting('order.register', ['orderId' => 'order-1'], metadata: ['tenant' => 'tenant-1']));
    }

    public function test_consumer_on_coroutines_with_a_client_the_application_connected_itself_is_refused(): void
    {
        $ecotone = $this->bootstrap(handlers: $this->orders(), connectionFactory: new RedisConnectionFactory($this->clientTheApplicationConnectedItself()));
        $ecotone->sendCommandWithRouting('order.register', ['orderId' => 'order-1'], metadata: ['tenant' => 'tenant-1']);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(self::CLIENT_OF_THE_APPLICATION_REFUSED);

        $ecotone->run('coroutine_orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages()->withCoroutines(3));
    }

    private function clientTheApplicationConnectedItself(): PRedis
    {
        $settings = parse_url($this->dsnOf('redis:'));

        $client = new PRedis(['scheme' => 'redis', 'host' => $settings['host'], 'port' => $settings['port'], 'password' => null, 'database' => null, 'path' => null, 'async' => false, 'persistent' => false, 'timeout' => 5.0, 'read_write_timeout' => null, 'ssl' => null, 'predis_options' => null]);
        $client->connect();

        return $client;
    }

    private function dsnOf(string $scheme): string
    {
        return preg_replace('/^redis:/', $scheme, getenv('REDIS_DSN') ?: 'redis://localhost:6379');
    }

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

    private function consumersOf(FlowTestSupport $ecotone, string $channelName, int $consumers): array
    {
        return array_fill(0, $consumers, static fn () => $ecotone->run($channelName, ExecutionPollingMetadata::createWithDefaults()->withExecutionTimeLimitInMilliseconds(1500)));
    }

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
        return $this->bootstrap(handlers: $this->orders(), connectionFactory: new RedisConnectionFactory($this->dsnOf($scheme)));
    }

    private function orders(): object
    {
        return new class () {
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
    }

    private function bootstrap(object $handlers, RedisConnectionFactory $connectionFactory, array $extensionObjects = []): FlowTestSupport
    {
        $queues = new RedisConnectionFactory($this->dsnOf('redis:'));
        foreach (['coroutine_orders', 'coroutine_shipments'] as $queueName) {
            $queues->createContext()->purgeQueue(new RedisDestination($queueName));
        }

        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handlers::class],
            containerOrAvailableServices: [$handlers, RedisConnectionFactory::class => $connectionFactory],
            extensionObjects: [
                RedisBackedMessageChannelBuilder::create('coroutine_orders')->withReceiveTimeout(50),
                RedisBackedMessageChannelBuilder::create('coroutine_shipments')->withReceiveTimeout(50),
                ...$extensionObjects,
            ],
            enabledModules: [ModulePackageList::REDIS_PACKAGE],
        );
    }
}
