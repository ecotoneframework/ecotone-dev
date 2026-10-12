<?php

declare(strict_types=1);

namespace Ecotone\Api\Amqp;

use Ecotone\Messaging\Support\Assert;
use Interop\Amqp\Impl\AmqpBind as EnqueueBinding;

/**
 * licence Apache-2.0
 */
class AmqpBinding
{
    public readonly EnqueueBinding $enqueueBinding;
    /**
     * @var string
     */
    private $queueName;
    /**
     * @var string
     */
    private $exchangeName;

    private function __construct(AmqpExchange $amqpExchange, AmqpQueue $amqpQueue, ?string $routingKey, array $arguments)
    {
        $this->queueName = $amqpQueue->getQueueName();
        $this->exchangeName = $amqpExchange->getExchangeName();
        $this->enqueueBinding = new EnqueueBinding($amqpExchange->toEnqueueExchange(), $amqpQueue->toEnqueueQueue(), $routingKey, 0, $arguments);
    }

    public function getQueueName(): string
    {
        return $this->queueName;
    }

    public function getExchangeName(): string
    {
        return $this->exchangeName;
    }

    public function isBindToDefaultExchange(): bool
    {
        return $this->exchangeName === '';
    }

    public function isRelatedToQueueName(string $amqpQueueName): bool
    {
        return $this->queueName == $amqpQueueName;
    }

    public function isRelatedToExchangeName(string $amqpExchangeName): bool
    {
        return $this->exchangeName == $amqpExchangeName;
    }

    public static function createWith(AmqpExchange $amqpExchange, AmqpQueue $amqpQueue, ?string $routingKey): self
    {
        return new self($amqpExchange, $amqpQueue, $routingKey, []);
    }

    public static function createForDefaultExchange(AmqpQueue $amqpQueue): self
    {
        return new self(AmqpExchange::createDirectExchange(''), $amqpQueue, $amqpQueue->getQueueName(), []);
    }

    public static function createFromNames(string $amqpExchangeName, string $amqpQueueName, ?string $routingKey): self
    {
        return new self(AmqpExchange::createDirectExchange($amqpExchangeName), AmqpQueue::createWith($amqpQueueName), $routingKey, []);
    }

    public static function createFromNamesWithoutRoutingKey(string $amqpExchangeName, string $amqpQueueName): self
    {
        return new self(AmqpExchange::createDirectExchange($amqpExchangeName), AmqpQueue::createWith($amqpQueueName), null, []);
    }

    public static function createHeadersBinding(AmqpExchange $amqpExchange, AmqpQueue $amqpQueueName, array $bindings): self
    {
        Assert::isTrue($amqpExchange->isHeadersExchange(), "Header binding can only be done on headers exchange {$amqpExchange->getExchangeName()} to {$amqpQueueName->getQueueName()}");

        return new self($amqpExchange, $amqpQueueName, null, $bindings);
    }
}
