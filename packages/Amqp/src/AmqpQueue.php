<?php

declare(strict_types=1);

namespace Ecotone\Amqp;

use Ecotone\Messaging\Support\Assert;
use Interop\Amqp\Impl\AmqpQueue as EnqueueQueue;

/**
 * Class AmqpQueue
 * @package Ecotone\Amqp
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
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

    /**
     * AmqpQueue constructor.
     * @param string $queueName
     */
    private function __construct(string $queueName)
    {
        $this->enqueueQueue = new EnqueueQueue($queueName);
    }

    /**
     * @return string
     */
    public function getQueueName(): string
    {
        return $this->enqueueQueue->getQueueName();
    }

    /**
     * @param string $queueName
     * @return AmqpQueue
     */
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

    /**
     * @return EnqueueQueue
     */
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

    public function withDeadLetterExchangeTarget(AmqpExchange $amqpExchange, ?string $routingKey = null): self
    {
        $this->withDeadLetterExchange = $amqpExchange->getExchangeName();
        $this->withDeadLetterRoutingKey = $routingKey;

        return $this;
    }

    public function withDeadLetterForDefaultExchange(AmqpQueue $amqpQueue): self
    {
        $this->withDeadLetterExchange = '';
        $this->withDeadLetterRoutingKey = $amqpQueue->getQueueName();

        return $this;
    }

    /**
     * the queue will survive a broker restart
     *
     * @param bool $isDurable
     * @return AmqpQueue
     */
    public function withDurability(bool $isDurable): self
    {
        Assert::isFalse($this->isStream, "Can't change durability for stream queue. It's always true.");
        $this->withDurability = $isDurable;

        return $this;
    }

    /**
     * used by only one connection and the queue will be deleted when that connection closes
     *
     * @return AmqpQueue
     */
    public function withExclusivity(): self
    {
        Assert::isFalse($this->isStream, "Can't change exclusivity for stream queue. It's always false.");
        $this->enqueueQueue->addFlag(EnqueueQueue::FLAG_EXCLUSIVE);

        return $this;
    }

    /**
     * queue that has had at least one consumer is deleted when last consumer unsubscribes
     *
     * @return AmqpQueue
     */
    public function withAutoDeletion(): self
    {
        Assert::isFalse($this->isStream, "Can't change auto delete for stream queue. It's always false.");
        $this->enqueueQueue->addFlag(EnqueueQueue::FLAG_AUTODELETE);

        return $this;
    }

    /**
     * optional, used by plugins and broker-specific features such as message TTL, queue length limit, etc
     *
     * @param string $name
     * @param $value
     * @return AmqpQueue
     */
    public function withArgument(string $name, $value): self
    {
        $this->enqueueQueue->setArgument($name, $value);

        return $this;
    }

    /**
     * Sets the maximum age of messages in the stream.
     * Messages older than this will be removed by retention policy.
     * Only applicable for stream queues.
     *
     * @param string $maxAge Duration string (e.g., '7D' for 7 days, '24h' for 24 hours, '30m' for 30 minutes, '60s' for 60 seconds)
     * @return self
     */
    public function withMaxAge(string $maxAge): self
    {
        Assert::isTrue($this->isStream, 'withMaxAge is only applicable for stream queues. Use createStreamQueue() to create a stream queue.');
        $this->enqueueQueue->setArgument('x-max-age', $maxAge);

        return $this;
    }

    /**
     * Sets the maximum size of the stream in bytes.
     * When exceeded, oldest segments will be removed.
     * Only applicable for stream queues.
     *
     * @param int $maxBytes Maximum size in bytes
     * @return self
     */
    public function withMaxLengthBytes(int $maxBytes): self
    {
        Assert::isTrue($this->isStream, 'withMaxLengthBytes is only applicable for stream queues. Use createStreamQueue() to create a stream queue.');
        $this->enqueueQueue->setArgument('x-max-length-bytes', $maxBytes);

        return $this;
    }

    /**
     * Sets the maximum size of stream segments in bytes.
     * Smaller segments allow more granular retention but may impact performance.
     * Only applicable for stream queues.
     *
     * @param int $segmentSize Segment size in bytes (default is 500MB in RabbitMQ)
     * @return self
     */
    public function withStreamMaxSegmentSizeBytes(int $segmentSize): self
    {
        Assert::isTrue($this->isStream, 'withStreamMaxSegmentSizeBytes is only applicable for stream queues. Use createStreamQueue() to create a stream queue.');
        $this->enqueueQueue->setArgument('x-stream-max-segment-size-bytes', $segmentSize);

        return $this;
    }

    public function isStream(): bool
    {
        return $this->isStream;
    }
}
