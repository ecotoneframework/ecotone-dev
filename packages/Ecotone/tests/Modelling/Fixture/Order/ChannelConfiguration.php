<?php

namespace Test\Ecotone\Modelling\Fixture\Order;

use Ecotone\Api\Environment;
use Ecotone\Api\PollingMetadata;
use Ecotone\Api\ServiceContext;
use Ecotone\Api\SimpleMessageChannelBuilder;

/**
 * licence Apache-2.0
 */
class ChannelConfiguration
{
    #[ServiceContext]
    #[Environment(['dev', 'prod'])]
    public function registerAsyncChannel(): array
    {
        return [
            SimpleMessageChannelBuilder::createQueueChannel('orders'),
            PollingMetadata::create('orders')
                ->setExecutionTimeLimitInMilliseconds(1)
                ->setHandledMessageLimit(1)
                ->setStopOnError(true),
        ];
    }
}
