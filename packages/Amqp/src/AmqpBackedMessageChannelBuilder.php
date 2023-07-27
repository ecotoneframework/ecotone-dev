<?php

namespace Ecotone\Amqp;

use Ecotone\Enqueue\EnqueueMessageChannelWithSerializationBuilder;
use Enqueue\AmqpExt\AmqpConnectionFactory;

/**
 * Class AmqpBackedMessageChannelBuilder
 * @package Ecotone\Amqp
 * @author  Dariusz Gafka <dgafka.mail@gmail.com>
 */
class AmqpBackedMessageChannelBuilder extends EnqueueMessageChannelWithSerializationBuilder
{
    private function __construct(string $channelName, string $amqpConnectionReferenceName)
    {
        parent::__construct(
            AmqpInboundChannelAdapterBuilder::createWith($channelName, $channelName, null, $amqpConnectionReferenceName),
            AmqpOutboundChannelAdapterBuilder::createForDefaultExchange($amqpConnectionReferenceName)
                ->withDefaultRoutingKey($channelName)
                ->withAutoDeclareOnSend(true)
                ->withDefaultPersistentMode(true)
        );
    }

    public static function create(string $channelName, string $amqpConnectionReferenceName = AmqpConnectionFactory::class)
    {
        return new self($channelName, $amqpConnectionReferenceName);
    }
}
