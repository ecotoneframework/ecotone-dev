<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Integration;

use Ecotone\Amqp\Configuration\AmqpConfiguration;
use Ecotone\Api\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Test\CoroutineTesting;
use Interop\Amqp\Impl\AmqpQueue;
use PHPUnit\Framework\Attributes\RequiresMethod;
use RuntimeException;
use Swoole\Coroutine;
use Test\Ecotone\Amqp\AmqpMessagingTestCase;
use Throwable;

/**
 * licence Apache-2.0
 * @internal
 */
#[RequiresMethod(Coroutine::class, 'create')]
final class CoroutineExecutionsTest extends AmqpMessagingTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        foreach (['coroutine_orders', 'coroutine_shipments'] as $queueName) {
            try {
                self::getRabbitConnectionFactory()->createContext()->deleteQueue(new AmqpQueue($queueName));
            } catch (Throwable) {
            }
        }
    }

    public function test_coroutines_publishing_and_consuming_at_once_handle_every_message_once_with_its_own_headers(): void
    {
        $ecotone = $this->bootstrapOrders(publisherConfirms: false, transactions: false);

        CoroutineTesting::runAtOnce(
            ...$this->publishersOfOrders($ecotone, publishers: 4, ordersOfEachPublisher: 25),
            ...$this->consumersOf($ecotone, 'coroutine_orders', consumers: 2),
            ...$this->consumersOf($ecotone, 'coroutine_shipments', consumers: 2),
        );
        $ecotone->run('coroutine_orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
        $ecotone->run('coroutine_shipments', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        $this->assertEquals($this->ordersWithTheirTenants(publishers: 4, ordersOfEachPublisher: 25), $ecotone->sendQueryWithRouting('order.shipped'));
    }

    public function test_consumers_on_coroutines_handle_every_message_once_with_its_own_headers(): void
    {
        $ecotone = $this->bootstrapOrders(publisherConfirms: false, transactions: false);
        CoroutineTesting::runAtOnce(...$this->publishersOfOrders($ecotone, publishers: 4, ordersOfEachPublisher: 25));

        $ecotone->run('coroutine_orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages()->withCoroutines(3));
        $ecotone->run('coroutine_shipments', ExecutionPollingMetadata::createWithFinishWhenNoMessages()->withCoroutines(3));

        $this->assertEquals($this->ordersWithTheirTenants(publishers: 4, ordersOfEachPublisher: 25), $ecotone->sendQueryWithRouting('order.shipped'));
    }

    public function test_coroutines_publishing_with_confirms_and_consuming_at_once_handle_every_message_once(): void
    {
        $ecotone = $this->bootstrapOrders(publisherConfirms: true, transactions: false);

        CoroutineTesting::runAtOnce(
            ...$this->publishersOfOrders($ecotone, publishers: 4, ordersOfEachPublisher: 25),
            ...$this->consumersOf($ecotone, 'coroutine_orders', consumers: 2),
            ...$this->consumersOf($ecotone, 'coroutine_shipments', consumers: 2),
        );
        $ecotone->run('coroutine_orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
        $ecotone->run('coroutine_shipments', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        $this->assertEquals($this->ordersWithTheirTenants(publishers: 4, ordersOfEachPublisher: 25), $ecotone->sendQueryWithRouting('order.shipped'));
    }

    public function test_coroutine_that_fails_discards_only_what_it_published_itself(): void
    {
        $ecotone = $this->bootstrapOrders(publisherConfirms: false, transactions: true);

        CoroutineTesting::runAtOnce(
            function () use ($ecotone): void {
                try {
                    $ecotone->sendCommandWithRouting('order.register', ['orderId' => 'refused', 'pauseSeconds' => 0.2, 'fail' => true], metadata: ['tenant' => 'tenant-of-refused']);
                } catch (RuntimeException) {
                }
            },
            function () use ($ecotone): void {
                usleep(50_000);
                $ecotone->sendCommandWithRouting('order.register', ['orderId' => 'accepted'], metadata: ['tenant' => 'tenant-of-accepted']);
            },
        );
        $ecotone->run('coroutine_orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
        $ecotone->run('coroutine_shipments', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        $this->assertSame(['accepted' => ['tenant-of-accepted']], $ecotone->sendQueryWithRouting('order.shipped'));
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
                    $ecotone->sendCommandWithRouting('order.register', ['orderId' => "order-{$publisher}-{$order}", 'pauseSeconds' => 0.002], metadata: ['tenant' => "tenant-{$publisher}-{$order}"]);
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

    private function bootstrapOrders(bool $publisherConfirms, bool $transactions): FlowTestSupport
    {
        $orders = new class () {
            private array $shipped = [];

            #[CommandHandler('order.register')]
            public function register(array $command, CommandBus $commandBus): void
            {
                $commandBus->sendWithRouting('order.place', $command['orderId']);

                if (isset($command['pauseSeconds'])) {
                    usleep((int) ($command['pauseSeconds'] * 1_000_000));
                }
                if ($command['fail'] ?? false) {
                    throw new RuntimeException('Order was refused');
                }
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

        return $this->bootstrapFlowTesting(
            [$orders::class],
            [$orders, ...$this->getConnectionFactoryReferences()],
            [
                AmqpConfiguration::createWithDefaults()->withTransactionOnCommandBus($transactions)->withTransactionOnAsynchronousEndpoints($transactions),
                AmqpBackedMessageChannelBuilder::create('coroutine_orders')->withPublisherConfirms($publisherConfirms)->withReceiveTimeout(50),
                AmqpBackedMessageChannelBuilder::create('coroutine_shipments')->withPublisherConfirms($publisherConfirms)->withReceiveTimeout(50),
            ],
            [ModulePackageList::AMQP_PACKAGE],
        );
    }
}
