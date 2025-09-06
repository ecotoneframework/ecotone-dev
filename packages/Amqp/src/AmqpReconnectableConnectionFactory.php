<?php

namespace Ecotone\Amqp;

use AMQPConnection;
use Ecotone\Enqueue\ReconnectableConnectionFactory;
use Ecotone\Messaging\Support\Assert;
use Enqueue\AmqpLib\AmqpConnectionFactory;
use Enqueue\AmqpExt\AmqpConsumer;
use Enqueue\AmqpLib\AmqpContext;
use Exception;
use Interop\Queue\ConnectionFactory;
use Interop\Queue\Context;
use Interop\Queue\SubscriptionConsumer;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPLazyConnection;
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

    public function __construct(AmqpConnectionFactory $connectionFactory, ?string $connectionInstanceId = null, private bool $publisherAcknowledgments = false)
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

        if ($this->publisherAcknowledgments) {
            $context->getLibChannel()->confirm_select();
        }

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

        /** @var AMQPChannel $libChannel */
        $libChannel = $context->getLibChannel();
        if ($libChannel->getConnection() !== null && !$libChannel->getConnection()->isConnected()) {
            return true;
        }

        return ! $libChannel->is_open();
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
        /** @var AMQPConnection|AMQPLazyConnection $connection */
        $connection = $connectionProperty->getValue($this->connectionFactory);
        if ($connection) {
            $connection->close();
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
