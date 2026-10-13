<?php

declare(strict_types=1);

namespace Ecotone\Api\Amqp;

use Ecotone\Messaging\Support\Assert;
use Interop\Amqp\Impl\AmqpQueue as EnqueueQueue;

/**
 * licence Apache-2.0
 */
class AmqpQueue
{
    private const DEFAULT_DURABILITY = true;

    private EnqueueQueue $enqueueQueue;
    private bool $withDurability = self::DEFAULT_DURABILITY;
    private ?string $withDeadLetterExchange = null;
    private ?string $withDeadLetterRoutingKey = null;
    private bool $isStream = false;

    private function __construct(string $queueName)
    {
        $this->enqueueQueue = new EnqueueQueue($queueName);
    }

    public function getQueueName(): string
    {
        return $this->enqueueQueue->getQueueName();
    }

    public static function createWith(string $queueName): self
    {
        return new self($queueName);
    }

    public static function createStreamQueue(string $queueName): self
    {
        $self = self::createWith($queueName);
        $self->enqueueQueue->setArgument('x-queue-type', 'stream');
        $self->isStream = true;
        $self->withDurability = true;

        return $self;
    }

    public function toEnqueueQueue(): EnqueueQueue
    {
        $amqpQueue = clone $this->enqueueQueue;

        if ($this->withDurability) {
            $amqpQueue->addFlag(EnqueueQueue::FLAG_DURABLE);
        }
        if (! is_null($this->withDeadLetterExchange)) {
            $amqpQueue->setArgument('x-dead-letter-exchange', $this->withDeadLetterExchange);
        }
        if ($this->withDeadLetterRoutingKey) {
            $amqpQueue->setArgument('x-dead-letter-routing-key', $this->withDeadLetterRoutingKey);
        }

        return $amqpQueue;
    }

    public function setDeadLetterExchangeTarget(AmqpExchange $amqpExchange, ?string $routingKey = null): self
    {
        $this->withDeadLetterExchange = $amqpExchange->getExchangeName();
        $this->withDeadLetterRoutingKey = $routingKey;

        return $this;
    }

    public function setDeadLetterForDefaultExchange(AmqpQueue $amqpQueue): self
    {
        $this->withDeadLetterExchange = '';
        $this->withDeadLetterRoutingKey = $amqpQueue->getQueueName();

        return $this;
    }

    public function setDurability(bool $isDurable): self
    {
        Assert::isFalse($this->isStream, "Can't change durability for stream queue. It's always true.");
        $this->withDurability = $isDurable;

        return $this;
    }

    public function setExclusivity(): self
    {
        Assert::isFalse($this->isStream, "Can't change exclusivity for stream queue. It's always false.");
        $this->enqueueQueue->addFlag(EnqueueQueue::FLAG_EXCLUSIVE);

        return $this;
    }

    public function setAutoDeletion(): self
    {
        Assert::isFalse($this->isStream, "Can't change auto delete for stream queue. It's always false.");
        $this->enqueueQueue->addFlag(EnqueueQueue::FLAG_AUTODELETE);

        return $this;
    }

    /**
     * @param $value
     */
    public function setArgument(string $name, $value): self
    {
        $this->enqueueQueue->setArgument($name, $value);

        return $this;
    }
}
