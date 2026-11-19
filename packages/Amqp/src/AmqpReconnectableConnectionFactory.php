<?php

namespace Ecotone\Amqp;

use AMQPBasicProperties;
use AMQPConnection;
use Ecotone\Api\Amqp\AmqpExtConnectionFactory;
use Ecotone\Api\Amqp\AmqpLibConnectionFactory;
use Ecotone\Enqueue\ReconnectableConnectionFactory;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Execution\ExecutionScope;
use Enqueue\AmqpExt\AmqpConsumer;
use Enqueue\AmqpExt\AmqpContext as AmqpExtContext;
use Enqueue\AmqpLib\AmqpContext as AmqpLibContext;
use Exception;
use Interop\Amqp\AmqpConnectionFactory;
use Interop\Queue\ConnectionFactory;
use Interop\Queue\Context;
use Interop\Queue\SubscriptionConsumer;
use PhpAmqpLib\Channel\AMQPChannel as LibAMQPChannel;
use PhpAmqpLib\Connection\AMQPLazyConnection;
use PhpAmqpLib\Message\AMQPMessage as LibAMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use ReflectionClass;
use ReflectionProperty;
use Throwable;
use WeakMap;

/**
 * licence Apache-2.0
 */
class AmqpReconnectableConnectionFactory implements ReconnectableConnectionFactory
{
    private string $connectionInstanceId;
    private AmqpConnectionFactory $connectionFactory;
    private ?SubscriptionConsumer $subscriptionConsumer = null;
    /** @var Context[] */
    private array $openContextsOfFinishedExecutions = [];
    /** @var WeakMap<Context, AmqpPublisherConfirmations> */
    private WeakMap $publisherConfirmationsOfContexts;
    /** @var WeakMap<Context, int> */
    private WeakMap $nanosecondsWhenContextsWereKeptOpen;
    private ?int $nanosecondsWhenThePublishingConnectionWasLastUsed = null;

    public static function definitionFor(string $connectionReferenceName, bool $publisherConfirms): Definition
    {
        return new Definition(self::class, [new Reference($connectionReferenceName), null, $publisherConfirms]);
    }

    public static function connectionKeyFor(string $connectionReferenceName, bool $publisherConfirms): string
    {
        return $connectionReferenceName . ($publisherConfirms ? '.confirms' : '');
    }

    public function __construct(AmqpExtConnectionFactory|AmqpLibConnectionFactory $connectionFactory, ?string $connectionInstanceId = null, private bool $publisherConfirms = false)
    {
        $this->connectionInstanceId = $connectionInstanceId !== null ? $connectionInstanceId : spl_object_id($connectionFactory);
        $this->publisherConfirmationsOfContexts = new WeakMap();
        $this->nanosecondsWhenContextsWereKeptOpen = new WeakMap();
        /** Each consumer and publisher requires separate connection to work correctly in all cases: https://www.rabbitmq.com/connections.html#flow-control */
        if ($connectionFactory instanceof AmqpExtConnectionFactory) {
            $this->connectionFactory = new AmqpExtConnectionFactory($connectionFactory->getConfig()->getConfig());
        } else {
            $this->connectionFactory = new AmqpLibConnectionFactory($connectionFactory->getConfig()->getConfig());
        }
    }

    public function createContext(): Context
    {
        $context = $this->takeOpenContextOfFinishedExecution() ?? $this->openContext();
        if (ExecutionScope::isConcurrent()) {
            ExecutionScope::stateOf($this, fn () => new ChannelsOfExecution($this->keepOpenForNextExecution(...)))->contexts[] = $context;
        }

        return $context;
    }

    private function takeOpenContextOfFinishedExecution(): ?Context
    {
        while ($this->openContextsOfFinishedExecutions !== []) {
            $context = array_pop($this->openContextsOfFinishedExecutions);
            if (! $this->isDisconnected($context) && ! $this->hasBrokerClosedAConnectionIdleSince($this->nanosecondsWhenContextsWereKeptOpen[$context] ?? null)) {
                return $context;
            }

            $this->publisherConfirmationsOf($context)?->reset();
        }

        return null;
    }

    /**
     * @param Context[] $contexts
     */
    private function keepOpenForNextExecution(array $contexts): void
    {
        foreach ($contexts as $context) {
            if ($this->canBeUsedByNextExecution($context)) {
                $this->openContextsOfFinishedExecutions[] = $context;
                $this->nanosecondsWhenContextsWereKeptOpen[$context] = (int) hrtime(true);

                continue;
            }

            $this->publisherConfirmationsOf($context)?->reset();
        }
    }

    private function canBeUsedByNextExecution(Context $context): bool
    {
        try {
            return ! $this->isDisconnected($context);
        } catch (Throwable) {
            return false;
        }
    }

    private function connectionFactoryOfCurrentExecution(): AmqpConnectionFactory
    {
        if (ExecutionScope::isConcurrent() && $this->connectionFactory instanceof AmqpExtConnectionFactory) {
            return new AmqpExtConnectionFactory($this->connectionFactory->getConfig()->getConfig());
        }
        if (ExecutionScope::isConcurrent() && $this->connectionFactory instanceof AmqpLibConnectionFactory) {
            return new AmqpLibConnectionFactory($this->connectionFactory->getConfig()->getConfig());
        }

        if (! $this->isConnected()) {
            $this->reconnect();
        }

        return $this->connectionFactory;
    }

    private function openContext(): Context
    {
        $context = $this->connectionFactoryOfCurrentExecution()->createContext();
        if (! ExecutionScope::isConcurrent()) {
            $this->nanosecondsWhenThePublishingConnectionWasLastUsed ??= (int) hrtime(true);
        }

        if ($this->publisherConfirms) {
            $confirmations = new AmqpPublisherConfirmations();
            $this->publisherConfirmationsOfContexts[$context] = $confirmations;
            if ($context instanceof AmqpLibContext) {
                $context->getLibChannel()->confirm_select();
                $context->getLibChannel()->set_ack_handler(fn (LibAMQPMessage $message) => $confirmations->recordConfirmationForCorrelation(self::publishCorrelationIdFrom($message)));
                $context->getLibChannel()->set_nack_handler(fn (LibAMQPMessage $message) => $confirmations->recordRejectionForCorrelation(self::publishCorrelationIdFrom($message)));
                $context->getLibChannel()->set_return_listener(function (int $replyCode, string $replyText, string $exchange, string $routingKey, LibAMQPMessage $message) use ($confirmations): void {
                    $confirmations->recordReturnedMessage(
                        self::publishCorrelationIdFrom($message),
                        sprintf('Message was returned as unroutable by RabbitMQ instance (%d %s) for exchange `%s` and routing key `%s`.', $replyCode, $replyText, $exchange, $routingKey),
                    );
                });
            } elseif ($context instanceof AmqpExtContext) {
                $context->getExtChannel()->confirmSelect();
                $context->getExtChannel()->setReturnCallback(function (int $replyCode, string $replyText, string $exchange, string $routingKey, AMQPBasicProperties $properties) use ($confirmations): bool {
                    $confirmations->recordReturnedMessage(
                        (string) ($properties->getHeaders()[AmqpPublisherConfirmations::PUBLISH_BATCH_ID_PROPERTY] ?? ''),
                        sprintf('Message was returned as unroutable by RabbitMQ instance (%d %s) for exchange `%s` and routing key `%s`.', $replyCode, $replyText, $exchange, $routingKey),
                    );

                    return true;
                });
                $context->getExtChannel()->setConfirmCallback(
                    function (int $deliveryTag, bool $multiple) use ($confirmations): bool {
                        $confirmations->recordConfirmation($deliveryTag, $multiple);

                        return $confirmations->hasOutstandingConfirmations();
                    },
                    function (int $deliveryTag, bool $multiple) use ($confirmations): bool {
                        $confirmations->recordRejection($deliveryTag, $multiple);

                        return $confirmations->hasOutstandingConfirmations();
                    }
                );
            }
        }

        return $context;
    }

    public function publisherConfirmationsOf(Context $context): ?AmqpPublisherConfirmations
    {
        return $this->publisherConfirmationsOfContexts[$context] ?? null;
    }

    private static function publishCorrelationIdFrom(LibAMQPMessage $message): string
    {
        $applicationHeaders = $message->get_properties()['application_headers'] ?? null;
        if ($applicationHeaders instanceof AMQPTable) {
            $applicationHeaders = $applicationHeaders->getNativeData();
        }

        $applicationHeaders = (array) $applicationHeaders;

        return (string) ($applicationHeaders[AmqpPublisherConfirmations::PUBLISH_BATCH_ID_PROPERTY] ?? '');
    }

    public function getConnectionInstanceId(): string
    {
        return get_class($this->connectionFactory) . $this->connectionInstanceId . ($this->publisherConfirms ? '.confirms' : '');
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
            if ($libChannel->getConnection() !== null && ! $libChannel->getConnection()->isConnected()) {
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

    public function hasBrokerClosedTheConnectionIdleSinceTheLastPublish(): bool
    {
        return ! ExecutionScope::isConcurrent() && $this->hasBrokerClosedAConnectionIdleSince($this->nanosecondsWhenThePublishingConnectionWasLastUsed);
    }

    public function recordPublish(): void
    {
        if (! ExecutionScope::isConcurrent()) {
            $this->nanosecondsWhenThePublishingConnectionWasLastUsed = (int) hrtime(true);
        }
    }

    private function hasBrokerClosedAConnectionIdleSince(?int $nanoseconds): bool
    {
        if ($nanoseconds === null || ! $this->connectionFactory instanceof AmqpExtConnectionFactory) {
            return false;
        }

        $heartbeatInSeconds = (float) $this->connectionFactory->getConfig()->getHeartbeat();

        return $heartbeatInSeconds > 0 && hrtime(true) - $nanoseconds >= 2 * $heartbeatInSeconds * 1_000_000_000;
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
            try {
                // Use method existence checks instead of instanceof to handle lazy connections
                // Lazy connections may not be instances of AMQPConnection until they're actually used
                if (method_exists($connection, 'disconnect')) {
                    $connection->disconnect();
                } elseif (method_exists($connection, 'close')) {
                    $connection->close();
                }
            } catch (Exception) {
                // Ignore errors during disconnection
            }
        }

        $connectionProperty->setValue($this->connectionFactory, null);
        $this->nanosecondsWhenThePublishingConnectionWasLastUsed = null;
        $this->subscriptionConsumer = null;
        $this->openContextsOfFinishedExecutions = [];
        foreach ($this->publisherConfirmationsOfContexts as $confirmations) {
            $confirmations->reset();
        }
    }

    private function isConnected(): bool
    {
        $connectionProperty = $this->getConnectionProperty();
        /** @var AMQPConnection|AMQPLazyConnection|null $connection */
        $connection = $connectionProperty->getValue($this->connectionFactory);

        if (! $connection) {
            return false;
        }

        // Use method existence check to handle lazy connections
        // Lazy connections may not be instances of AMQPConnection until they're actually used
        if (method_exists($connection, 'isConnected')) {
            return $connection->isConnected();
        }

        // If the connection object exists but doesn't have isConnected method, assume it's connected
        return true;
    }

    private function getConnectionProperty(): ReflectionProperty
    {
        return (new ReflectionClass($this->connectionFactory))->getProperty('connection');
    }

    public function getSubscriptionConsumer(string $queueName, callable $subscriptionCallback): SubscriptionConsumer
    {
        if ($this->subscriptionConsumer === null) {
            $context = $this->openContext();

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
