<?php

namespace Ecotone\Amqp;

use AMQPChannel as ExtAMQPChannel;
use AMQPConnection;
use Ecotone\Enqueue\ReconnectableConnectionFactory;
use Enqueue\AmqpExt\AmqpConnectionFactory as AmqpExtConnectionFactory;
use Enqueue\AmqpExt\AmqpConsumer;
use Enqueue\AmqpExt\AmqpContext as AmqpExtContext;
use Enqueue\AmqpLib\AmqpConnectionFactory as AmqpLibConnectionFactory;
use Enqueue\AmqpLib\AmqpContext as AmqpLibContext;
use Exception;
use Interop\Queue\ConnectionFactory;
use Interop\Queue\Context;
use Interop\Queue\SubscriptionConsumer;
use PhpAmqpLib\Channel\AMQPChannel as LibAMQPChannel;
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
    private AmqpExtConnectionFactory|AmqpLibConnectionFactory $connectionFactory;
    private ?SubscriptionConsumer $subscriptionConsumer = null;

    public function __construct(AmqpExtConnectionFactory|AmqpLibConnectionFactory $connectionFactory, ?string $connectionInstanceId = null, private bool $publisherAcknowledgments = false)
    {
        $this->connectionInstanceId = $connectionInstanceId !== null ? $connectionInstanceId : spl_object_id($connectionFactory);
        /** Each consumer and publisher requires separate connection to work correctly in all cases: https://www.rabbitmq.com/connections.html#flow-control */
        if ($connectionFactory instanceof AmqpExtConnectionFactory) {
            $this->connectionFactory = new AmqpExtConnectionFactory($connectionFactory->getConfig()->getConfig());
        } else {
            $this->connectionFactory = new AmqpLibConnectionFactory($connectionFactory->getConfig()->getConfig());
        }
    }

    public function createContext(): Context
    {
        if (! $this->isConnected()) {
            $this->reconnect();
        }

        $context = $this->connectionFactory->createContext();

        if ($this->publisherAcknowledgments) {
            if ($context instanceof AmqpLibContext) {
                $context->getLibChannel()->confirm_select();
            } elseif ($context instanceof AmqpExtContext) {
                $context->getExtChannel()->confirmSelect();
                $context->getExtChannel()->setConfirmCallback(fn () => false, fn () => throw new RuntimeException('Message was failed to be persisted in RabbitMQ instance. Check RabbitMQ server logs.'));
            }
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
     * @param Context|AmqpExtContext|AmqpLibContext|null $context
     */
    public function isDisconnected(?Context $context): bool
    {
        if (! $context) {
            return false;
        }

        if ($context instanceof AmqpLibContext) {
            /** @var LibAMQPChannel $libChannel */
            $libChannel = $context->getLibChannel();
            if ($libChannel->getConnection() !== null && !$libChannel->getConnection()->isConnected()) {
                return true;
            }
            return ! $libChannel->is_open();
        } elseif ($context instanceof AmqpExtContext) {
            if (! $context->getExtChannel()->getConnection()->isConnected()) {
                return true;
            }
            return ! $context->getExtChannel()->isConnected();
        }

        return false;
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
