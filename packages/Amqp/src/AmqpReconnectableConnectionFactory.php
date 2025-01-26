<?php

namespace Ecotone\Amqp;

use AMQPConnection;
use Ecotone\Enqueue\ReconnectableConnectionFactory;
use Ecotone\Messaging\Support\Assert;
use Enqueue\AmqpExt\AmqpConnectionFactory;
use Enqueue\AmqpExt\AmqpConsumer;
use Enqueue\AmqpExt\AmqpContext;
use Exception;
use Interop\Queue\ConnectionFactory;
use Interop\Queue\Context;
use Interop\Queue\SubscriptionConsumer;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

/**
 * licence Apache-2.0
 */
class AmqpReconnectableConnectionFactory implements ReconnectableConnectionFactory
{
    private string $connectionInstanceId;
    private AmqpConnectionFactory $connectionFactory;
    private ?SubscriptionConsumer $subscriptionConsumer = null;

    public function __construct(AmqpConnectionFactory $connectionFactory, ?string $connectionInstanceId = null)
    {
        $this->connectionInstanceId = $connectionInstanceId !== null ? $connectionInstanceId : spl_object_id($connectionFactory);
        /** Each consumer and publisher requires separate connection to work correctly in all cases: https://www.rabbitmq.com/connections.html#flow-control */
        $this->connectionFactory = new AmqpConnectionFactory($connectionFactory->getConfig()->getConfig());
    }

    public function createContext(): Context
    {
        if (! $this->isConnected()) {
            $this->reconnect();
        }

        $context = $this->connectionFactory->createContext();
        $context->getExtChannel()->setConfirmCallback(fn () => false, fn () => throw new RuntimeException('Message was failed to be persisted in RabbitMQ instance. Check RabbitMQ server logs.'));

        return $context;
    }

    public function getConnectionInstanceId(): string
    {
        return get_class($this->connectionFactory) . $this->connectionInstanceId;
    }

    /**
     * No way to reliable state if amqp is connected: https://github.com/php-amqp/php-amqp/issues/306
     * So to make it more reliable we check other way around, if is disconnected.
     *
     * There are situations where connection to AMQP connections becomes zombies.
     * In that scenarios triggering an action on the connection will do nothing and will not throw an exception.
     * It makes the feeling like anything is fine, yet in reality it is not.
     * In those situations it's better to use this method.
     * @param Context|AmqpContext|null $context
     */
    public function isDisconnected(?Context $context): bool
    {
        if (! $context) {
            return false;
        }

        Assert::isSubclassOf($context, AmqpContext::class, 'Context must be ' . AmqpContext::class);

        if (! $context->getExtChannel()->getConnection()->isConnected()) {
            return true;
        }

        return ! $context->getExtChannel()->isConnected();
    }

    public function reconnect(): void
    {
        $connectionProperty = $this->getConnectionProperty();

        if ($this->subscriptionConsumer) {
            try {
                $this->subscriptionConsumer->unsubscribeAll();
            } catch (Exception) {
            }
        }
        /** @var AMQPConnection $connection */
        $connection = $connectionProperty->getValue($this->connectionFactory);
        if ($connection) {
            $connection->disconnect();
        }

        $connectionProperty->setValue($this->connectionFactory, null);
        $this->subscriptionConsumer = null;
    }

    private function isConnected(): bool
    {
        $connectionProperty = $this->getConnectionProperty();
        /** @var AMQPConnection $connection */
        $connection = $connectionProperty->getValue($this->connectionFactory);

        return $connection ? $connection->isConnected() : false;
    }

    private function getConnectionProperty(): ReflectionProperty
    {
        $reflectionClass = new ReflectionClass($this->connectionFactory);

        $connectionProperty = $reflectionClass->getProperty('connection');
        $connectionProperty->setAccessible(true);

        return $connectionProperty;
    }

    public function getSubscriptionConsumer(string $queueName, callable $subscriptionCallback): SubscriptionConsumer
    {
        if ($this->subscriptionConsumer === null) {
            /** @var AmqpContext $context */
            $context = $this->createContext();

            $this->subscriptionConsumer = $context->createSubscriptionConsumer();

            /** @var AmqpConsumer $consumer */
            $consumer = $context->createConsumer(
                $context->createQueue($queueName)
            );

            $this->subscriptionConsumer->subscribe($consumer, $subscriptionCallback);
        }

        return $this->subscriptionConsumer;
    }

    public function getWrappedConnectionFactory(): ConnectionFactory
    {
        return $this->connectionFactory;
    }
}
