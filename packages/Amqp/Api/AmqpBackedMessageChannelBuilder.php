<?php

namespace Ecotone\Api\Amqp;

use Ecotone\Amqp\AmqpInboundChannelAdapterBuilder;
use Ecotone\Amqp\AmqpOutboundChannelAdapterBuilder;
use Ecotone\Amqp\Pulse\AmqpChannelDepthProbe;
use Ecotone\Enqueue\EnqueueMessageChannelBuilder;
use Ecotone\Messaging\Channel\DepthProbedMessageChannelBuilder;
use Ecotone\Messaging\Channel\MessageChannelBuilderOnSameConnection;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;

/**
 * licence Apache-2.0
 */
class AmqpBackedMessageChannelBuilder extends EnqueueMessageChannelBuilder implements MessageChannelBuilderOnSameConnection, DepthProbedMessageChannelBuilder
{
    private string $channelName;
    private string $amqpConnectionReferenceName;
    private string $queueName;

    private function __construct(
        string $channelName,
        string $amqpConnectionReferenceName,
        string $queueName
    ) {
        $this->channelName = $channelName;
        $this->amqpConnectionReferenceName = $amqpConnectionReferenceName;
        $this->queueName = $queueName;

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

    public function depthProbeGroup(): string
    {
        return 'amqp:' . $this->amqpConnectionReferenceName;
    }

    public function depthProbeDestination(): string
    {
        return $this->queueName;
    }

    public function depthProbeDefinition(): Definition
    {
        return new Definition(AmqpChannelDepthProbe::class, [new Reference($this->amqpConnectionReferenceName)]);
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
