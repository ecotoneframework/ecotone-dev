<?php

declare(strict_types=1);

namespace Ecotone\Api\Amqp;

use Ecotone\Amqp\AmqpOutboundChannelAdapterBuilder;
use Ecotone\Amqp\AmqpStreamInboundChannelAdapterBuilder;
use Ecotone\Enqueue\EnqueueMessageChannelBuilder;

/**
 * licence Enterprise
 *
 * Use requires an Ecotone Enterprise licence (see LICENSE-ENTERPRISE; trial: https://ecotone.tech/pricing).
 */
class AmqpStreamChannelBuilder extends EnqueueMessageChannelBuilder
{
    private string $channelName;
    public readonly string $messageGroupId;

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
     * @link https://docs.ecotone.tech/modules/amqp-support-rabbitmq/message-channel#start-position-options
     */
    public static function create(
        string  $channelName,
        string  $startPosition = 'first',
        string  $amqpConnectionReferenceName = AmqpConnectionReference::DEFAULT_STREAM,
        ?string $queueName = null,
        ?string $messageGroupId = null
    ): self {
        $queueName ??= $channelName;

        return new self($channelName, $amqpConnectionReferenceName, $queueName, $startPosition, $messageGroupId);
    }

    /**
     * @link https://docs.ecotone.tech/modules/amqp-support-rabbitmq/message-channel#prefetch-count
     */
    public function withPrefetchCount(int $prefetchCount): static
    {
        /** @var AmqpStreamInboundChannelAdapterBuilder $inboundAdapter */
        $inboundAdapter = $this->getInboundChannelAdapter();
        $inboundAdapter->withPrefetchCount($prefetchCount);

        return $this;
    }

    /**
     * @link https://docs.ecotone.tech/modules/amqp-support-rabbitmq/message-channel#commit-interval
     */
    public function withCommitInterval(int $commitInterval): static
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

    public function isStreamingChannel(): bool
    {
        return true;
    }

    public function __toString()
    {
        return sprintf('AMQP Stream Channel - %s', $this->channelName);
    }
}
