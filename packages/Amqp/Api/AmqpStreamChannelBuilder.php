<?php

declare(strict_types=1);

namespace Ecotone\Api\Amqp;

use Ecotone\Amqp\AmqpOutboundChannelAdapterBuilder;
use Ecotone\Amqp\AmqpStreamInboundChannelAdapterBuilder;
use Ecotone\Enqueue\EnqueueMessageChannelBuilder;
use Ecotone\Messaging\Config\ConfigurationException;

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
        if ($startPosition === 'next') {
            throw ConfigurationException::create("AMQP stream channel `{$channelName}` cannot start at `next`: RabbitMQ delivers a `next` consumer only what is published after it attaches, so every message published before the consumer's first poll, or between two of its runs, is never delivered. Start at `first`, at `last`, at an absolute offset such as `100`, or at a timestamp such as `1700000000`; after the first commit the consumer resumes from its committed offset whichever start position it was given.");
        }
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
