<?php

namespace App\MultiTenant;

use Ecotone\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Channel\DynamicChannel\DynamicMessageChannelBuilder;
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
            DbalConfiguration::createWithDefaults()
                ->withTransactionOnCommandBus(false)
                ->withTransactionOnAsynchronousEndpoints(false)
                ->withDeduplication(false)
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