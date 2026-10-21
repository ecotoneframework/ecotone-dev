<?php

namespace Ecotone\Api\Amqp;

use Ecotone\Amqp\AmqpInboundChannelAdapterBuilder;
use Ecotone\Amqp\AmqpOutboundChannelAdapterBuilder;
use Ecotone\Enqueue\EnqueueMessageChannelBuilder;
use Ecotone\Messaging\Channel\MessageChannelBuilderOnSameConnection;

/**
 * licence Apache-2.0
 */
class AmqpBackedMessageChannelBuilder extends EnqueueMessageChannelBuilder implements MessageChannelBuilderOnSameConnection
{
    private string $channelName;
    private string $amqpConnectionReferenceName;

    private function __construct(
        string $channelName,
        string $amqpConnectionReferenceName,
        string $queueName
    ) {
        $this->channelName = $channelName;
        $this->amqpConnectionReferenceName = $amqpConnectionReferenceName;

        parent::__construct(
            AmqpInboundChannelAdapterBuilder::createWith($channelName, $queueName, null, $amqpConnectionReferenceName),
            AmqpOutboundChannelAdapterBuilder::createForDefaultExchange($amqpConnectionReferenceName)
                ->withDefaultRoutingKey($queueName)
                ->withAutoDeclareOnSend(true)
                ->withDefaultPersistentMode(true)
                ->withPublishingChannelName($channelName)
        );
    }

    /**
     * @link https://docs.ecotone.tech/modules/amqp-support-rabbitmq/message-channel
     */
    public static function create(
        string $channelName,
        string $amqpConnectionReferenceName = AmqpConnectionReference::DEFAULT,
        ?string $queueName = null
    ): self {
        return new self(
            $channelName,
            $amqpConnectionReferenceName,
            $queueName ?? $channelName
        );
    }

    public function forSendOnlyChannelOnSameConnection(string $channelName): self
    {
        return self::create($channelName, $this->amqpConnectionReferenceName)->withRequiredExistingDestination();
    }

    private function getAmqpOutboundChannelAdapter(): AmqpOutboundChannelAdapterBuilder
    {
        return $this->outboundChannelAdapter;
    }

    public function withRequiredExistingDestination(): static
    {
        $this->outboundChannelAdapter->withRequiredExistingDestination();

        return $this;
    }

    public function withPublisherConfirms(bool $enabled): static
    {
        $this->outboundChannelAdapter->withPublisherConfirms($enabled);

        return $this;
    }

    /**
     * @link https://docs.ecotone.tech/modules/amqp-support-rabbitmq/message-channel#high-throughput-publishing-enterprise
     */
    public function withHighThroughputPublishing(bool $batchPublishing = true, bool $nonBlockingConfirmation = true, ?int $confirmationTimeoutInMilliseconds = null): static
    {
        $this->getAmqpOutboundChannelAdapter()->withHighThroughputPublishing($batchPublishing, $nonBlockingConfirmation, $confirmationTimeoutInMilliseconds);

        return $this;
    }

    public function withDelayStrategy(string $delayStrategyReferenceName): static
    {
        $this->getAmqpOutboundChannelAdapter()->withDelayStrategy($delayStrategyReferenceName);

        return $this;
    }

    public function getMessageChannelName(): string
    {
        return $this->channelName;
    }

    protected function supportsBatchMessages(): bool
    {
        return $this->getAmqpOutboundChannelAdapter()->isBatchPublishingEnabled();
    }

    public function getQueueName()
    {
        return $this->getInboundChannelAdapter()->getMessageChannelName();
    }
}
