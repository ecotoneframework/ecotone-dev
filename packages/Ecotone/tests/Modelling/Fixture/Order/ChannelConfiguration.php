<?php

namespace Test\Ecotone\Modelling\Fixture\Order;

use Ecotone\AnnotationFinder\Attribute\Environment;
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Channel\SimpleMessageChannelWithSerializationBuilder;
use Ecotone\Messaging\Endpoint\PollingMetadata;

class ChannelConfiguration
{
    #[ServiceContext]
    #[Environment(['dev', 'prod'])]
    public function registerAsyncChannel(): array
    {
        return [
            SimpleMessageChannelWithSerializationBuilder::createQueueChannel('orders'),
            PollingMetadata::create('orders')
                ->setExecutionTimeLimitInMilliseconds(1)
                ->setHandledMessageLimit(1),
        ];
    }
}
