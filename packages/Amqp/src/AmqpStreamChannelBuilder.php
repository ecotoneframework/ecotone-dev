<?php

declare(strict_types=1);

namespace Ecotone\Amqp;

use Ecotone\Enqueue\EnqueueMessageChannelBuilder;
use Enqueue\AmqpLib\AmqpConnectionFactory;

/**
 * licence Enterprise
 */
class AmqpStreamChannelBuilder extends EnqueueMessageChannelBuilder
{
    private string $channelName;
    private string $messageGroupId;
    private ?string $maxAge = null;
    private ?int $maxLengthBytes = null;
    private ?int $streamMaxSegmentSizeBytes = null;

    private function __construct(
        string $channelName,
        string $amqpConnectionReferenceName,
        public readonly string $queueName,
        string $streamOffset,
        ?string $messageGroupId = null
    ) {
        $this->channelName = $channelName;
        $this->messageGroupId = $messageGroupId ?? $channelName;

        parent::__construct(
            AmqpStreamInboundChannelAdapterBuilder::create($this->channelName, $queueName, $streamOffset, $this->messageGroupId, $amqpConnectionReferenceName),
            AmqpOutboundChannelAdapterBuilder::createForDefaultExchange($amqpConnectionReferenceName)
                ->withDefaultRoutingKey($queueName)
                ->withAutoDeclareOnSend(true)
                ->withDefaultPersistentMode(true)
        );
    }

    /**
     * Create a stream channel with consume method enabled and stream queue type
     *
     * @param string $channelName
     * @param string $startPosition Stream offset: 'first', 'last', 'next', or specific offset number
     * @param string $amqpConnectionReferenceName
     * @param string|null $queueName If null, channel name will be used as queue name
     * @param string|null $messageGroupId If null, channel name will be used as message group id. This the default consumer group for Consumer with id equal to channel name
     * @return self
     */
    public static function create(
        string  $channelName,
        string  $startPosition = 'first',
        string  $amqpConnectionReferenceName = AmqpConnectionFactory::class,
        ?string $queueName = null,
        ?string $messageGroupId = null
    ): self {
        $queueName ??= $channelName;

        return new self($channelName, $amqpConnectionReferenceName, $queueName, $startPosition, $messageGroupId);
    }

    /**
     * Set the prefetch count (QoS) for stream consumption
     *
     * Controls how many unacknowledged messages RabbitMQ will deliver to the consumer.
     * Lower values (e.g., 1) provide better flow control but may reduce throughput.
     * Higher values allow faster consumption but use more memory.
     *
     * @param int $prefetchCount Number of messages to prefetch (default: 100)
     * @return self
     */
    public function withPrefetchCount(int $prefetchCount): self
    {
        /** @var AmqpStreamInboundChannelAdapterBuilder $inboundAdapter */
        $inboundAdapter = $this->getInboundChannelAdapter();
        $inboundAdapter->withPrefetchCount($prefetchCount);

        return $this;
    }

    /**
     * Set the commit interval for position tracking
     *
     * Controls how often the consumer position is committed to the position tracker.
     * - commitInterval=1: Commit after every message (default, safest but slowest)
     * - commitInterval=10: Commit after every 10 messages (better performance, small risk of reprocessing)
     * - The last message in a batch is always committed, even if the interval hasn't been reached
     *
     * Example with commitInterval=2 and 5 messages:
     * - Commits happen at offsets: 2, 4, 5 (5 is committed because it's the last in the batch)
     *
     * @param int $commitInterval Number of messages to process before committing position (default: 100)
     * @return self
     */
    public function withCommitInterval(int $commitInterval): self
    {
        /** @var AmqpStreamInboundChannelAdapterBuilder $inboundAdapter */
        $inboundAdapter = $this->getInboundChannelAdapter();
        $inboundAdapter->withCommitInterval($commitInterval);

        return $this;
    }

    /**
     * Sets the maximum age of messages in the stream.
     * Messages older than this will be removed by retention policy.
     *
     * @param string $maxAge Duration string (e.g., '7D' for 7 days, '24h' for 24 hours, '30m' for 30 minutes, '60s' for 60 seconds)
     * @return self
     */
    public function withMaxAge(string $maxAge): self
    {
        $this->maxAge = $maxAge;

        return $this;
    }

    /**
     * Sets the maximum size of the stream in bytes.
     * When exceeded, oldest segments will be removed.
     *
     * @param int $maxBytes Maximum size in bytes
     * @return self
     */
    public function withMaxLengthBytes(int $maxBytes): self
    {
        $this->maxLengthBytes = $maxBytes;

        return $this;
    }

    /**
     * Sets the maximum size of stream segments in bytes.
     * Smaller segments allow more granular retention but may impact performance.
     *
     * @param int $segmentSize Segment size in bytes (default is 500MB in RabbitMQ)
     * @return self
     */
    public function withStreamMaxSegmentSizeBytes(int $segmentSize): self
    {
        $this->streamMaxSegmentSizeBytes = $segmentSize;

        return $this;
    }

    /**
     * Returns an AmqpQueue configured with the stream settings from this builder.
     */
    public function getAmqpQueue(): AmqpQueue
    {
        $queue = AmqpQueue::createStreamQueue($this->queueName);

        if ($this->maxAge !== null) {
            $queue->withMaxAge($this->maxAge);
        }
        if ($this->maxLengthBytes !== null) {
            $queue->withMaxLengthBytes($this->maxLengthBytes);
        }
        if ($this->streamMaxSegmentSizeBytes !== null) {
            $queue->withStreamMaxSegmentSizeBytes($this->streamMaxSegmentSizeBytes);
        }

        return $queue;
    }

    public function getMessageChannelName(): string
    {
        return $this->channelName;
    }

    public function getMessageGroupId(): string
    {
        return $this->messageGroupId;
    }

    public function isPollable(): bool
    {
        return true;
    }

    public function isStreamingChannel(): bool
    {
        return true;
    }

    public function __toString()
    {
        return \sprintf('AMQP Stream Channel - %s', $this->channelName);
    }
}
