<?php

namespace Test\Ecotone\Amqp;

use AMQPQueueException;
use Ecotone\Amqp\Distribution\AmqpDistributionModule;
use Enqueue\AmqpExt\AmqpConnectionFactory as AmqpExtConnection;
use Enqueue\AmqpLib\AmqpConnectionFactory as AmqpLibConnection;
use Interop\Amqp\AmqpConnectionFactory;
use Interop\Amqp\Impl\AmqpQueue;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Amqp\Fixture\DistributedDeadLetter\Receiver\TicketServiceMessagingConfiguration;
use Test\Ecotone\Amqp\Fixture\ErrorChannel\ErrorConfigurationContext;
use Test\Ecotone\Amqp\Fixture\FailureTransactionWithFatalError\ChannelConfiguration;
use Test\Ecotone\Amqp\Fixture\Shop\MessagingConfiguration;

/**
 * licence Apache-2.0
 */
abstract class AmqpMessagingTestCase extends TestCase
{
    public const RABBITMQ_HOST = 'localhost';

    public const RABBITMQ_USER = 'guest';

    public const RABBITMQ_PASSWORD = 'guest';

    /**
     * @return AmqpConnectionFactory
     */
    public function getCachedConnectionFactory(array $config = []): AmqpConnectionFactory
    {
        return self::getRabbitConnectionFactory($config);
    }

    /**
     * Get connection factory references for dependency injection container
     * Returns an array with all possible connection factory class names pointing to the same instance
     * This ensures compatibility with both AmqpExt and AmqpLib implementations
     * 
     * @return array<string, AmqpConnectionFactory>
     */
    public function getConnectionFactoryReferences(array $config = []): array
    {
        $connectionFactory = $this->getCachedConnectionFactory($config);
        
        // Provide both the interface and both concrete implementations
        // Even though only AmqpExt is installed, some modules (like AmqpTransactionModule)
        // default to AmqpLib, so we need to provide it as well
        return [
            AmqpConnectionFactory::class => $connectionFactory,
            AmqpExtConnection::class => $connectionFactory,
            AmqpLibConnection::class => $connectionFactory,
        ];
    }

    /**
     * @return AmqpConnectionFactory
     */
    public static function getRabbitConnectionFactory(array $config = []): AmqpConnectionFactory
    {
        $dsn = ['dsn' => getenv('RABBIT_HOST') ?: 'amqp://guest:guest@localhost:5672/%2f'];
        $config = array_merge($dsn, $config);

        // Use AMQP_IMPLEMENTATION env var to choose between ext and lib
        // Default to ext for backward compatibility
        $implementation = getenv('AMQP_IMPLEMENTATION') ?: 'ext';

        if ($implementation === 'lib') {
            return new AmqpLibConnection($config);
        }

        return new AmqpExtConnection($config);
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
        $this->deleteQueue(new AmqpQueue('async'));
        $this->deleteQueue(new AmqpQueue('notification_channel'));
        $this->deleteQueue(new AmqpQueue('test_queue'));
    }

    private function deleteQueue(AmqpQueue $queue): void
    {
        try {
            self::getRabbitConnectionFactory()->createContext()->deleteQueue($queue);
        } catch (AMQPQueueException) {
        }
    }
}
