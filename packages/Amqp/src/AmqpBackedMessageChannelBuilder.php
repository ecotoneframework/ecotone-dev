<?php

namespace Ecotone\Amqp;

use Ecotone\Enqueue\EnqueueMessageChannelBuilder;
use Ecotone\Messaging\Endpoint\FinalFailureStrategy;
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

    public function withPublisherAcknowledgments(bool $enabled): self
    {
        $this->getAmqpOutboundChannelAdapter()->withPublisherAcknowledgments($enabled);

        return $this;
    }

    public function getMessageChannelName(): string
    {
        return $this->channelName;
    }

    public function getQueueName()
    {
        return $this->getInboundChannelAdapter()->getMessageChannelName();
    }
}
