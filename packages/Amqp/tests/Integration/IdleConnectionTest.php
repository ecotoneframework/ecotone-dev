<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Integration;

use Ecotone\Api\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
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

    private function bootstrapOrdersWithHeartbeatOfOneSecond(bool $publisherConfirms): FlowTestSupport
    {
        $orders = new class () {
            private array $placed = [];

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
            [$orders, ...$this->getConnectionFactoryReferences(['heartbeat' => 1])],
            [AmqpBackedMessageChannelBuilder::create('idle_orders')->withPublisherConfirms($publisherConfirms)],
            [ModulePackageList::AMQP_PACKAGE],
        );
    }
}
