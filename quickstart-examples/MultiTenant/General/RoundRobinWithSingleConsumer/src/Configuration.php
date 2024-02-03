<?php

namespace General\RoundRobinWithSingleConsumer\src;

use Ecotone\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Channel\DynamicChannel\DynamicMessageChannelBuilder;
use Ecotone\Messaging\Channel\DynamicChannel\RoundRobinChannelBuilder;
use Ecotone\Messaging\Endpoint\PollingMetadata;

class Configuration
{
    #[ServiceContext]
    public function channelConfig(): array
    {
        return [
            DynamicMessageChannelBuilder::createRoundRobin("image_processing", [
                'image_processing_one',
                'image_processing_two',
            ]),
            AmqpBackedMessageChannelBuilder::create("image_processing_one"),
            AmqpBackedMessageChannelBuilder::create("image_processing_two"),
        ];
    }

    #[ServiceContext]
    public function consumerDefinition(): PollingMetadata
    {
        return PollingMetadata::create('image_processing')
                    ->setHandledMessageLimit(1)
                    ->setExecutionAmountLimit(1)
                    ->setStopOnError(true);
    }
}