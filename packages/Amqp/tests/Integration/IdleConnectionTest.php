<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Integration;

use Ecotone\Amqp\Configuration\AmqpConfiguration;
use Ecotone\Api\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Fiber;
use Interop\Amqp\Impl\AmqpQueue;
use Test\Ecotone\Amqp\AmqpMessagingTestCase;
use Throwable;

/**
 * licence Apache-2.0
 * @internal
 */
final class IdleConnectionTest extends AmqpMessagingTestCase
{
    private const SECONDS_AFTER_WHICH_THE_BROKER_CLOSES_AN_IDLE_CONNECTION = 4;

    public function setUp(): void
    {
        parent::setUp();
        try {
            self::getRabbitConnectionFactory()->createContext()->deleteQueue(new AmqpQueue('idle_orders'));
        } catch (Throwable) {
        }
    }

    public function test_publishing_continues_after_the_broker_closed_the_connection_that_stayed_idle(): void
    {
        $ecotone = $this->bootstrapOrdersWithHeartbeatOfOneSecond(publisherConfirms: false);

        $ecotone->sendCommandWithRouting('order.place', 'before idle');
        sleep(self::SECONDS_AFTER_WHICH_THE_BROKER_CLOSES_AN_IDLE_CONNECTION);
        $ecotone->sendCommandWithRouting('order.place', 'after idle');
        $ecotone->run('idle_orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        $this->assertSame(['before idle', 'after idle'], $ecotone->sendQueryWithRouting('order.placed'));
    }

    public function test_publishing_with_confirms_continues_after_the_broker_closed_the_connection_that_stayed_idle(): void
    {
        $ecotone = $this->bootstrapOrdersWithHeartbeatOfOneSecond(publisherConfirms: true);

        $ecotone->sendCommandWithRouting('order.place', 'before idle');
        sleep(self::SECONDS_AFTER_WHICH_THE_BROKER_CLOSES_AN_IDLE_CONNECTION);
        $ecotone->sendCommandWithRouting('order.place', 'after idle');
        $ecotone->run('idle_orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        $this->assertSame(['before idle', 'after idle'], $ecotone->sendQueryWithRouting('order.placed'));
    }

    public function test_consumer_that_is_not_stopped_on_error_continues_after_the_broker_closed_the_connection_that_stayed_idle(): void
    {
        $ecotone = $this->bootstrapOrdersWithHeartbeatOfOneSecond(publisherConfirms: false);

        $ecotone->sendCommandWithRouting('order.place', 'before idle');
        $ecotone->run('idle_orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));
        sleep(self::SECONDS_AFTER_WHICH_THE_BROKER_CLOSES_AN_IDLE_CONNECTION);
        $ecotone->sendCommandWithRouting('order.place', 'after idle');
        $ecotone->run('idle_orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));

        $this->assertSame(['before idle', 'after idle'], $ecotone->sendQueryWithRouting('order.placed'));
    }

    public function test_execution_publishes_after_the_broker_closed_the_connection_of_a_channel_kept_open_for_it(): void
    {
        $ecotone = $this->bootstrapOrdersWithHeartbeatOfOneSecond(publisherConfirms: true);

        (new Fiber(fn () => $ecotone->sendCommandWithRouting('order.place', 'before idle')))->start();
        sleep(self::SECONDS_AFTER_WHICH_THE_BROKER_CLOSES_AN_IDLE_CONNECTION);
        (new Fiber(fn () => $ecotone->sendCommandWithRouting('order.place', 'after idle')))->start();
        $ecotone->run('idle_orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        $this->assertSame(['before idle', 'after idle'], $ecotone->sendQueryWithRouting('order.placed'));
    }

    public function test_transaction_of_a_handler_that_works_for_most_of_the_heartbeat_is_committed_once(): void
    {
        $ecotone = $this->bootstrapOrders(publisherConfirms: false, heartbeatInSeconds: 2, transactions: true);

        $ecotone->sendCommandWithRouting('order.placeAfterWorkingFor', ['orderId' => 'worked on', 'seconds' => 1.2]);
        $ecotone->run('idle_orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        $this->assertSame(['worked on'], $ecotone->sendQueryWithRouting('order.placed'));
        $this->assertSame(1, $ecotone->sendQueryWithRouting('order.timesWorkedOn'));
    }

    public function test_publish_in_a_transaction_is_committed_once_after_transactions_that_published_nothing_kept_the_connection_in_use(): void
    {
        $ecotone = $this->bootstrapOrders(publisherConfirms: false, heartbeatInSeconds: 1, transactions: true);

        for ($check = 1; $check <= 6; $check++) {
            $ecotone->sendCommandWithRouting('order.check');
            usleep(500_000);
        }
        $ecotone->sendCommandWithRouting('order.placeAfterWorkingFor', ['orderId' => 'after checks', 'seconds' => 0]);
        $ecotone->run('idle_orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        $this->assertSame(['after checks'], $ecotone->sendQueryWithRouting('order.placed'));
        $this->assertSame(1, $ecotone->sendQueryWithRouting('order.timesWorkedOn'));
    }

    private function bootstrapOrdersWithHeartbeatOfOneSecond(bool $publisherConfirms): FlowTestSupport
    {
        return $this->bootstrapOrders($publisherConfirms, heartbeatInSeconds: 1, transactions: false);
    }

    private function bootstrapOrders(bool $publisherConfirms, int $heartbeatInSeconds, bool $transactions): FlowTestSupport
    {
        $orders = new class () {
            private array $placed = [];
            private int $timesWorkedOn = 0;

            #[CommandHandler('order.placeAfterWorkingFor')]
            public function placeAfterWorkingFor(array $command, CommandBus $commandBus): void
            {
                $this->timesWorkedOn++;
                $commandBus->sendWithRouting('order.place', $command['orderId']);
                usleep((int) ($command['seconds'] * 1_000_000));
            }

            #[CommandHandler('order.check')]
            public function check(): void
            {
            }

            #[QueryHandler('order.timesWorkedOn')]
            public function timesWorkedOn(): int
            {
                return $this->timesWorkedOn;
            }

            #[Asynchronous('idle_orders')]
            #[CommandHandler('order.place', endpointId: 'order.place.endpoint')]
            public function place(string $orderId): void
            {
                $this->placed[] = $orderId;
            }

            #[QueryHandler('order.placed')]
            public function placed(): array
            {
                return $this->placed;
            }
        };

        return $this->bootstrapFlowTesting(
            [$orders::class],
            [$orders, ...$this->getConnectionFactoryReferences(['heartbeat' => $heartbeatInSeconds])],
            [
                AmqpConfiguration::createWithDefaults()->withTransactionOnCommandBus($transactions),
                AmqpBackedMessageChannelBuilder::create('idle_orders')->withPublisherConfirms($publisherConfirms),
            ],
            [ModulePackageList::AMQP_PACKAGE],
        );
    }
}
