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
use Interop\Amqp\Impl\AmqpQueue;
use Test\Ecotone\Amqp\AmqpMessagingTestCase;
use Throwable;

/**
 * licence Apache-2.0
 * @internal
 */
final class PublishOutsideTransactionTest extends AmqpMessagingTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        foreach (['boundary_orders', 'boundary_shipments'] as $queueName) {
            try {
                self::getRabbitConnectionFactory()->createContext()->deleteQueue(new AmqpQueue($queueName));
            } catch (Throwable) {
            }
        }
    }

    public function test_message_sent_by_an_asynchronous_handler_is_delivered_when_only_the_command_bus_is_transactional(): void
    {
        $orders = new class () {
            private array $shipped = [];

            #[Asynchronous('boundary_orders')]
            #[CommandHandler('order.place', endpointId: 'order.place.endpoint')]
            public function place(string $orderId, CommandBus $commandBus): void
            {
                $commandBus->sendWithRouting('order.ship', $orderId);
            }

            #[Asynchronous('boundary_shipments')]
            #[CommandHandler('order.ship', endpointId: 'order.ship.endpoint')]
            public function ship(string $orderId): void
            {
                $this->shipped[] = $orderId;
            }

            #[QueryHandler('order.shipped')]
            public function shipped(): array
            {
                return $this->shipped;
            }
        };
        $ecotone = $this->bootstrapFlowTesting(
            [$orders::class],
            [$orders, ...$this->getConnectionFactoryReferences()],
            [
                AmqpConfiguration::createWithDefaults()->withTransactionOnCommandBus(true)->withTransactionOnAsynchronousEndpoints(false),
                AmqpBackedMessageChannelBuilder::create('boundary_orders')->withPublisherConfirms(false),
                AmqpBackedMessageChannelBuilder::create('boundary_shipments')->withPublisherConfirms(false),
            ],
            [ModulePackageList::AMQP_PACKAGE],
        );

        $ecotone->sendCommandWithRouting('order.place', 'order-1');
        $ecotone->run('boundary_orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
        $ecotone->run('boundary_shipments', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1, executionTimeLimitInMilliseconds: 2000));

        $this->assertSame(['order-1'], $ecotone->sendQueryWithRouting('order.shipped'));
    }
}
