<?php

namespace Ecotone\Amqp;

use Ecotone\Enqueue\EnqueueMessageChannelBuilder;
use Enqueue\AmqpExt\AmqpConnectionFactory;

/**
 * Class AmqpBackedMessageChannelBuilder
 * @package Ecotone\Amqp
 * @author  Dariusz Gafka <support@simplycodedsoftware.com>
 */
/**
 * licence Apache-2.0
 */
class AmqpBackedMessageChannelBuilder extends EnqueueMessageChannelBuilder
{
    private string $channelName;

    private function __construct(
        string $channelName,
        string $amqpConnectionReferenceName,
        string $queueName
    ) {
        $this->channelName = $channelName;

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
     * @param string|null $queueName If null, channel name will be used as queue name
     */
    public static function create(
        string $channelName,
        string $amqpConnectionReferenceName = AmqpConnectionFactory::class,
        ?string $queueName = null
    ) {
        return new self(
            $channelName,
            $amqpConnectionReferenceName,
            $queueName ?? $channelName
        );
    }

    private function getAmqpOutboundChannelAdapter(): AmqpOutboundChannelAdapterBuilder
    {
        return $this->outboundChannelAdapter;
    }

    /**
     * @deprecated use withPublisherConfirms
     * @TODO Ecotone 2.0 remove
     */
    public function withPublisherAcknowledgments(bool $enabled): self
    {
        $this->outboundChannelAdapter->withPublisherConfirms($enabled);

        return $this;
    }

    public function withPublisherConfirms(bool $enabled): self
    {
        $this->outboundChannelAdapter->withPublisherConfirms($enabled);

        return $this;
    }

    /**
     * @param bool $batchPublishing coalesces published Messages into a single publisher confirms round trip
     * @param bool $nonBlockingConfirmation publishes without waiting for publisher confirms, which are awaited before the surrounding Command Bus or asynchronous endpoint finishes
     * @param int|null $confirmationTimeoutInMilliseconds how long to await publisher confirms before treating the delivery as failed
     */
    public function withHighThroughputPublishing(bool $batchPublishing = true, bool $nonBlockingConfirmation = true, ?int $confirmationTimeoutInMilliseconds = null): self
    {
        $this->getAmqpOutboundChannelAdapter()->withHighThroughputPublishing($batchPublishing, $nonBlockingConfirmation, $confirmationTimeoutInMilliseconds);

        return $this;
    }

    public function withDelayStrategy(string $delayStrategyReferenceName): self
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
