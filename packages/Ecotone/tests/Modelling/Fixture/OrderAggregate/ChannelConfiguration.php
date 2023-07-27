<?php

namespace Test\Ecotone\Modelling\Fixture\OrderAggregate;

use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Channel\SimpleMessageChannelWithSerializationBuilder;
use Ecotone\Messaging\Endpoint\PollingMetadata;

class ChannelConfiguration
{
    public const ERROR_CHANNEL = 'errorChannel';

    #[ServiceContext]
    public function registerAsyncChannel(): array
    {
        return [
            SimpleMessageChannelWithSerializationBuilder::createQueueChannel('orders'),
            PollingMetadata::create('orders')
                ->setExecutionTimeLimitInMilliseconds(1)
                ->setHandledMessageLimit(1)
                ->setErrorChannelName(self::ERROR_CHANNEL),
        ];
    }
}
