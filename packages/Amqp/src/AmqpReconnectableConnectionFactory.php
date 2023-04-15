<?php

namespace Ecotone\Amqp;

use AMQPConnection;
use Ecotone\Enqueue\ReconnectableConnectionFactory;
use Ecotone\Messaging\Support\Assert;
use Enqueue\AmqpExt\AmqpConnectionFactory;
use Enqueue\AmqpExt\AmqpConsumer;
use Enqueue\AmqpExt\AmqpContext;
use Interop\Queue\Context;
use Interop\Queue\SubscriptionConsumer;
use ReflectionClass;
use ReflectionProperty;

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

        return $this->connectionFactory->createContext();
    }

    public function getConnectionInstanceId(): string
    {
        return get_class($this->connectionFactory) . $this->connectionInstanceId;
    }

    /**
     * @param Context|AmqpContext|null $context
     */
    public function isDisconnected(?Context $context): bool
    {
        if (! $context) {
            return false;
        }

        Assert::isSubclassOf($context, AmqpContext::class, 'Context must be ' . AmqpContext::class);

        return ! $context->getExtChannel()->isConnected();
    }

    public function reconnect(): void
    {
        $connectionProperty = $this->getConnectionProperty();

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
        $context = $this->createContext();
        if ($this->subscriptionConsumer === null) {
            $this->subscriptionConsumer = $context->createSubscriptionConsumer();

            /** @var AmqpConsumer $consumer */
            $consumer = $context->createConsumer(
                $context->createQueue($queueName)
            );

            $this->subscriptionConsumer->subscribe($consumer, $subscriptionCallback);
        }

        return $this->subscriptionConsumer;
    }
}
