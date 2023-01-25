<?php

namespace Test\Ecotone\Amqp;

use AMQPQueueException;
use Ecotone\Amqp\Distribution\AmqpDistributionModule;
use Enqueue\AmqpExt\AmqpConnectionFactory as AmqpLibConnection;
use Interop\Amqp\AmqpConnectionFactory;
use Interop\Amqp\Impl\AmqpQueue;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Amqp\Fixture\DistributedDeadLetter\Receiver\TicketServiceMessagingConfiguration;
use Test\Ecotone\Amqp\Fixture\ErrorChannel\ErrorConfigurationContext;
use Test\Ecotone\Amqp\Fixture\FailureTransactionWithFatalError\ChannelConfiguration;
use Test\Ecotone\Amqp\Fixture\Shop\MessagingConfiguration;

abstract class AmqpMessagingTest extends TestCase
{
    public const RABBITMQ_HOST = 'localhost';

    public const RABBITMQ_USER = 'guest';

    public const RABBITMQ_PASSWORD = 'guest';

    /**
     * @return AmqpConnectionFactory
     */
    public function getCachedConnectionFactory(): AmqpConnectionFactory
    {
        return $this->getRabbitConnectionFactory();
    }

    /**
     * @return AmqpConnectionFactory
     */
    public function getRabbitConnectionFactory(): AmqpConnectionFactory
    {
        return new AmqpLibConnection(['dsn' => getenv('RABBIT_HOST') ? getenv('RABBIT_HOST') : 'amqp://guest:guest@localhost:5672/%2f']);
    }

    public function setUp(): void
    {
        $this->queueCleanUp();
    }

    public function queueCleanUp(): void
    {
        $this->deleteQueue(new AmqpQueue(ChannelConfiguration::QUEUE_NAME));
        $this->deleteQueue(new AmqpQueue(Fixture\FailureTransaction\ChannelConfiguration::QUEUE_NAME));
        $this->deleteQueue(new AmqpQueue(Fixture\SuccessTransaction\ChannelConfiguration::QUEUE_NAME));
        $this->deleteQueue(new AmqpQueue(MessagingConfiguration::SHOPPING_QUEUE));
        $this->deleteQueue(new AmqpQueue(Fixture\Order\ChannelConfiguration::QUEUE_NAME));
        $this->deleteQueue(new AmqpQueue(ErrorConfigurationContext::INPUT_CHANNEL));
        $this->deleteQueue(new AmqpQueue(Fixture\DeadLetter\ErrorConfigurationContext::INPUT_CHANNEL));
        $this->deleteQueue(new AmqpQueue(Fixture\DeadLetter\ErrorConfigurationContext::DEAD_LETTER_CHANNEL));
        $this->deleteQueue(new AmqpQueue('distributed_ticket_service'));
        $this->deleteQueue(new AmqpQueue(AmqpDistributionModule::CHANNEL_PREFIX . TicketServiceMessagingConfiguration::SERVICE_NAME));
        $this->deleteQueue(new AmqpQueue('ecotone_1_delay'));
    }

    private function deleteQueue(AmqpQueue $queue): void
    {
        try {
            $this->getRabbitConnectionFactory()->createContext()->deleteQueue($queue);
        } catch (AMQPQueueException) {
        }
    }
}
