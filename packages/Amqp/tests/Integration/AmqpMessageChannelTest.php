<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Integration;

use AMQPConnectionException;
use AMQPQueueException;
use Ecotone\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Endpoint\PollingConsumer\ConnectionException;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;
use Ecotone\Messaging\PollableChannel;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Test\StubLogger;
use Enqueue\AmqpExt\AmqpConnectionFactory;
use Interop\Amqp\Impl\AmqpQueue;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use AMQPException;
use PHPUnit\Framework\Attributes\TestWith;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\Amqp\AmqpMessagingTestCase;
use Test\Ecotone\Amqp\Fixture\DeadLetter\ErrorConfigurationContext;
use Test\Ecotone\Amqp\Fixture\Order\OrderService;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class AmqpMessageChannelTest extends AmqpMessagingTestCase
{
    public function test_sending_and_receiving_message_from_amqp_message_channel()
    {
        $queueName = Uuid::uuid4()->toString();
        $messagePayload = 'some';

        $ecotoneLite = $this->bootstrapForTesting(
            containerOrAvailableServices: [
                ...$this->getConnectionFactoryReferences(),
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
            $messageChannel->receiveWithTimeout(100)->getPayload()
        );

        $this->assertNull($messageChannel->receiveWithTimeout(1));
    }

    public function test_sending_and_receiving_without_delivery_guarantee()
    {
        $queueName = Uuid::uuid4()->toString();
        $messagePayload = 'some';

        $ecotoneLite = $this->bootstrapForTesting(
            containerOrAvailableServices: [
                ...$this->getConnectionFactoryReferences(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE]))
                ->withExtensionObjects([
                    AmqpBackedMessageChannelBuilder::create($queueName)
                        ->withPublisherConfirms(false),
                ])
        );

        /** @var PollableChannel $messageChannel */
        $messageChannel = $ecotoneLite->getMessageChannelByName($queueName);

        $messageChannel->send(MessageBuilder::withPayload($messagePayload)->build());

        $this->assertEquals(
            'some',
            $messageChannel->receiveWithTimeout(100)->getPayload()
        );

        $this->assertNull($messageChannel->receiveWithTimeout(1));
    }

    public function test_sending_and_receiving_message_from_amqp_using_consumer()
    {
        $queueName = 'orders';

        $ecotoneLite = $this->bootstrapForTesting(
            [OrderService::class],
            [
                new OrderService(),
                ...$this->getConnectionFactoryReferences(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    AmqpBackedMessageChannelBuilder::create($queueName),
                ])
        );

        try {
            $this->getRabbitConnectionFactory()->createContext()->purgeQueue(new AmqpQueue($queueName));
        } catch (AMQPException|AMQPQueueException|AMQPProtocolChannelException) {
        }

        $ecotoneLite->getCommandBus()->sendWithRouting('order.register', 'milk');
        /** Message should be waiting in the queue */
        $this->assertEquals([], $ecotoneLite->getQueryBus()->sendWithRouting('order.getOrders'));

        $ecotoneLite->run('orders', ExecutionPollingMetadata::createWithDefaults()->withTestingSetup());
        /** Message should cosumed from the queue */
        $this->assertEquals(['milk'], $ecotoneLite->getQueryBus()->sendWithRouting('order.getOrders'));

        $ecotoneLite->run('orders', ExecutionPollingMetadata::createWithDefaults()->withTestingSetup());
        /** Nothing should change, as we have not sent any new command message */
        $this->assertEquals(['milk'], $ecotoneLite->getQueryBus()->sendWithRouting('order.getOrders'));
    }

    public function test_using_amqp_channel_with_custom_queue_name()
    {
        $channelName = 'orders';
        $queueName = 'orders_queue';

        $ecotoneLite = $this->bootstrapForTesting(
            [OrderService::class],
            [
                new OrderService(),
                ...$this->getConnectionFactoryReferences(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    AmqpBackedMessageChannelBuilder::create(
                        channelName: $channelName,
                        queueName: $queueName,
                    ),
                ])
        );

        try {
        $this->getRabbitConnectionFactory()->createContext()->purgeQueue(new AmqpQueue($queueName));
        } catch (AMQPException|AMQPQueueException|AMQPProtocolChannelException) {
        }
        $ecotoneLite->getCommandBus()->sendWithRouting('order.register', 'milk');
        /** Message should be waiting in the queue */
        $this->assertEquals([], $ecotoneLite->getQueryBus()->sendWithRouting('order.getOrders'));

        $ecotoneLite->run($channelName, ExecutionPollingMetadata::createWithDefaults()->withTestingSetup(
            maxExecutionTimeInMilliseconds: 2000,
        ));
        /** Message should be consumed from the queue */
        $this->assertEquals(['milk'], $ecotoneLite->getQueryBus()->sendWithRouting('order.getOrders'));

        $ecotoneLite->run($channelName, ExecutionPollingMetadata::createWithDefaults()->withTestingSetup(
            maxExecutionTimeInMilliseconds: 2000,
        ));
        /** Nothing should change, as we have not sent any new command message */
        $this->assertEquals(['milk'], $ecotoneLite->getQueryBus()->sendWithRouting('order.getOrders'));

        $this->getRabbitConnectionFactory()->createContext()->purgeQueue(new AmqpQueue($queueName));
    }

    /**
     * Ensure we can switch between consumption processes within same process.
     * This will fails for using "consume" with amqp lib, as it only works correctly using single consumer per queue
     *
     * @depends test_using_amqp_channel_with_custom_queue_name
     */
    public function test_using_amqp_channel_with_duplicated_queue_name()
    {
        $channelName = 'orders';
        $queueName = 'orders_queue';
        $this->getRabbitConnectionFactory()->createContext()->purgeQueue(new AmqpQueue($queueName));

        $ecotoneLite = $this->bootstrapForTesting(
            [OrderService::class],
            [
                new OrderService(),
                ...$this->getConnectionFactoryReferences(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    AmqpBackedMessageChannelBuilder::create(
                        channelName: $channelName,
                        queueName: $queueName,
                    ),
                ])
        );

        $ecotoneLite->getCommandBus()->sendWithRouting('order.register', 'milk');

        $ecotoneLite->run($channelName, ExecutionPollingMetadata::createWithDefaults()->withTestingSetup(
            maxExecutionTimeInMilliseconds: 2000,
        ));
        /** Message should be consumed from the queue */
        $this->assertEquals(['milk'], $ecotoneLite->getQueryBus()->sendWithRouting('order.getOrders'));

        $this->getRabbitConnectionFactory()->createContext()->purgeQueue(new AmqpQueue($queueName));
    }

    public function test_failing_to_receive_message_when_not_declared()
    {
        $queueName = Uuid::uuid4()->toString();
        $messagePayload = 'some';

        $ecotoneLite = $this->bootstrapForTesting(
            containerOrAvailableServices: [
                ...$this->getConnectionFactoryReferences(),
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

        // AMQP Ext throws AMQPException, AMQP Lib throws AMQPProtocolChannelException
        $this->expectException(\Throwable::class);

        $messageChannel->receiveWithTimeout(1);
    }

    public function test_failing_to_consume_due_to_connection_failure()
    {
        $loggerExample = StubLogger::create();
        $ecotoneLite = $this->bootstrapForTesting(
            [\Test\Ecotone\Amqp\Fixture\DeadLetter\OrderService::class],
            containerOrAvailableServices: [
                new \Test\Ecotone\Amqp\Fixture\DeadLetter\OrderService(),
                ...array_merge([
                    AmqpConnectionFactory::class => new AmqpConnectionFactory(['dsn' => 'amqp://guest:guest@localhost:1000/%2f']),
                    \Enqueue\AmqpExt\AmqpConnectionFactory::class => new AmqpConnectionFactory(['dsn' => 'amqp://guest:guest@localhost:1000/%2f']),
                    \Enqueue\AmqpLib\AmqpConnectionFactory::class => new AmqpConnectionFactory(['dsn' => 'amqp://guest:guest@localhost:1000/%2f']),
                ]),
                'logger' => $loggerExample,
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withConnectionRetryTemplate(
                    RetryTemplateBuilder::exponentialBackoff(1, 3)->maxRetryAttempts(3)
                )
                ->withExtensionObjects([
                    AmqpBackedMessageChannelBuilder::create('correctOrders'),
                ])
        );

        $wasFinallyRethrown = false;
        try {
            $ecotoneLite->run(
                'correctOrders',
                ExecutionPollingMetadata::createWithDefaults()
                    ->withHandledMessageLimit(1)
                    ->withExecutionTimeLimitInMilliseconds(100)
                    ->withStopOnError(false)
            );
        } catch (AMQPIOException|AMQPConnectionException) {
            $wasFinallyRethrown = true;
        }

        $this->assertTrue($wasFinallyRethrown, 'Connection exception was not propagated');
        $this->assertEquals(
            [
                'Message Consumer starting to consume messages',
                ConnectionException::connectionRetryMessage(1, 1),
                ConnectionException::connectionRetryMessage(2, 3),
                ConnectionException::connectionRetryMessage(3, 9),
            ],
            $loggerExample->getInfo()
        );
    }

    public function test_sending_to_dead_letter_as_another_amqp_channel()
    {
        $queueName = ErrorConfigurationContext::INPUT_CHANNEL;

        $ecotoneLite = $this->bootstrapForTesting(
            [\Test\Ecotone\Amqp\Fixture\DeadLetter\OrderService::class, ErrorConfigurationContext::class],
            [
                new \Test\Ecotone\Amqp\Fixture\DeadLetter\OrderService(),
                ...$this->getConnectionFactoryReferences(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withFailFast(false),
        );

        $ecotoneLite->run('incorrectOrdersEndpoint');

        /** https://www.rabbitmq.com/channels.html */
        $ecotoneLite->getCommandBus()->sendWithRouting('order.register', 'milk');
        /** Nothing was done yet */
        $this->assertEquals(0, $ecotoneLite->getQueryBus()->sendWithRouting('getOrderAmount'));
        $this->assertEquals(0, $ecotoneLite->getQueryBus()->sendWithRouting('getIncorrectOrderAmount'));

        /** We consume the message and fail. First retry is done to same queue */
        $ecotoneLite->run($queueName);
        $ecotoneLite->run('incorrectOrdersEndpoint');
        $this->assertEquals(0, $ecotoneLite->getQueryBus()->sendWithRouting('getOrderAmount'));
        $this->assertEquals(0, $ecotoneLite->getQueryBus()->sendWithRouting('getIncorrectOrderAmount'));

        /** We consume the message and fail. Second retry is done to same queue */
        $ecotoneLite->run($queueName);
        $ecotoneLite->run('incorrectOrdersEndpoint');
        $this->assertEquals(0, $ecotoneLite->getQueryBus()->sendWithRouting('getOrderAmount'));
        $this->assertEquals(0, $ecotoneLite->getQueryBus()->sendWithRouting('getIncorrectOrderAmount'));

        /** We consume the message and fail. Message moves to dead letter queue */
        $ecotoneLite->run($queueName);
        $ecotoneLite->run('incorrectOrdersEndpoint');
        $this->assertEquals(0, $ecotoneLite->getQueryBus()->sendWithRouting('getOrderAmount'));
        $this->assertEquals(1, $ecotoneLite->getQueryBus()->sendWithRouting('getIncorrectOrderAmount'));
    }
}
