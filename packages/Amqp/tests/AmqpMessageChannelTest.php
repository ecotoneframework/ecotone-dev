<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp;

use Ecotone\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Endpoint\PollingConsumer\ConnectionException;
use Ecotone\Messaging\PollableChannel;
use Ecotone\Messaging\Support\MessageBuilder;
use Enqueue\AmqpExt\AmqpConnectionFactory;
use Interop\Amqp\Impl\AmqpQueue;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\Amqp\Fixture\Order\OrderService;

/**
 * @internal
 */
final class AmqpMessageChannelTest extends AmqpMessagingTest
{
    public function test_sending_and_receiving_message_from_amqp_message_channel()
    {
        $queueName = Uuid::uuid4()->toString();
        $messagePayload = 'some';

        $ecotoneLite = EcotoneLite::bootstrapForTesting(
            containerOrAvailableServices: [
                AmqpConnectionFactory::class => $this->getRabbitConnectionFactory(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE]))
                ->withExtensionObjects([
                    AmqpBackedMessageChannelBuilder::create($queueName),
                ])
        );

        /** @var PollableChannel $messageChannel */
        $messageChannel = $ecotoneLite->getMessageChannelByName($queueName);

        $messageChannel->send(MessageBuilder::withPayload($messagePayload)->build());

        $this->assertEquals(
            'some',
            $messageChannel->receiveWithTimeout(1)->getPayload()
        );

        $this->assertNull($messageChannel->receiveWithTimeout(1));
    }

    public function test_sending_and_receiving_message_from_amqp_using_consumer()
    {
        $queueName = 'orders';

        $ecotoneLite = EcotoneLite::bootstrapForTesting(
            [OrderService::class],
            [
                new OrderService(),
                AmqpConnectionFactory::class => $this->getRabbitConnectionFactory(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    AmqpBackedMessageChannelBuilder::create($queueName),
                ])
        );

        try {
            $this->getRabbitConnectionFactory()->createContext()->purgeQueue(new AmqpQueue($queueName));
        }catch (\AMQPQueueException) {}

        $ecotoneLite->getCommandBus()->sendWithRouting('order.register', "milk");
        /** Message should be waiting in the queue */
        $this->assertEquals([], $ecotoneLite->getQueryBus()->sendWithRouting('order.getOrders'));

        $ecotoneLite->run('orders', ExecutionPollingMetadata::createWithDefaults()->withTestingSetup());
        /** Message should cosumed from the queue */
        $this->assertEquals(['milk'], $ecotoneLite->getQueryBus()->sendWithRouting('order.getOrders'));

        $ecotoneLite->run('orders', ExecutionPollingMetadata::createWithDefaults()->withTestingSetup());
        /** Nothing should change, as we have not sent any new command message */
        $this->assertEquals(['milk'], $ecotoneLite->getQueryBus()->sendWithRouting('order.getOrders'));
    }

    public function test_failing_to_receive_message_when_not_declared()
    {
        $queueName = Uuid::uuid4()->toString();
        $messagePayload = 'some';

        $ecotoneLite = EcotoneLite::bootstrapForTesting(
            containerOrAvailableServices: [
                AmqpConnectionFactory::class => $this->getRabbitConnectionFactory(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE]))
                ->withExtensionObjects([
                    AmqpBackedMessageChannelBuilder::create($queueName)
                        ->withAutoDeclare(false),
                ])
        );

        /** @var PollableChannel $messageChannel */
        $messageChannel = $ecotoneLite->getMessageChannelByName($queueName);

        $messageChannel->send(MessageBuilder::withPayload($messagePayload)->build());

        $this->expectException(\AMQPQueueException::class);

        $messageChannel->receiveWithTimeout(1);
    }
}
