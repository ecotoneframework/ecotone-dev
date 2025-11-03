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
    private bool $isShared = false;

    private function __construct(
        string $channelName,
        string $amqpConnectionReferenceName,
        public readonly string $queueName,
        string $streamOffset
    ) {
        $this->channelName = $channelName;

        parent::__construct(
            AmqpStreamInboundChannelAdapterBuilder::create($channelName, $queueName, $streamOffset, $amqpConnectionReferenceName),
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
     * @return self
     */
    public static function create(
        string  $channelName,
        string  $startPosition = 'first',
        string  $amqpConnectionReferenceName = AmqpConnectionFactory::class,
        ?string $queueName = null
    ): self {
        $queueName ??= $channelName;

        return new self($channelName, $amqpConnectionReferenceName, $queueName, $startPosition);
    }

    /**
     * Create a shared stream channel with consumer groups
     *
     * Shared channels allow multiple consumer groups to consume from the same stream independently.
     * Each consumer group tracks its own position in the stream.
     *
     * For Event Handlers on shared channels, the routing slip will point to the Event Bus
     * instead of specific handlers, ensuring all handlers execute together when a message is consumed.
     *
     * @param string $channelName
     * @param string $queueName The AMQP stream queue name
     * @param string $defaultEndpointId Default consumer group identifier (e.g., application name or service name)
     * @param string $startPosition Stream offset: 'first', 'last', 'next', or specific offset number
     * @param string $amqpConnectionReferenceName
     * @return self
     */
    public static function createShared(
        string $channelName,
        string $queueName,
        string $defaultEndpointId,
        string $startPosition = 'first',
        string $amqpConnectionReferenceName = AmqpConnectionFactory::class
    ): self {
        $instance = new self($channelName, $amqpConnectionReferenceName, $queueName, $startPosition);
        $instance->isShared = true;
        /** @var AmqpStreamInboundChannelAdapterBuilder $inboundAdapter */
        $inboundAdapter = $instance->getInboundChannelAdapter();
        $inboundAdapter->withEndpointId($defaultEndpointId);

        return $instance;
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

    public function getMessageChannelName(): string
    {
        return $this->channelName;
    }

    public function isPollable(): bool
    {
        return true;
    }

    /**
     * Check if this is a shared channel with consumer groups
     *
     * @return bool
     */
    public function isSharedChannel(): bool
    {
        return $this->isShared;
    }

    public function isShared(): bool
    {
        return $this->isShared;
    }

    public function __toString()
    {
        return sprintf('AMQP Stream Channel - %s', $this->channelName);
    }
}
