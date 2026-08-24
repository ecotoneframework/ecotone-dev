<?php

namespace Test\Ecotone\Messaging\Fixture\Behat\Presend;

use Ecotone\Api\PollingMetadata;
use Ecotone\Api\ServiceContext;
use Ecotone\Api\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Conversion\MediaType;

/**
 * licence Apache-2.0
 */
class PresendConfiguration
{
    #[ServiceContext]
    public function shopBuyConfiguration()
    {
        return [
            SimpleMessageChannelBuilder::createQueueChannel('shop', conversionMediaType: MediaType::createApplicationXPHP()),
            PollingMetadata::create('shop')
                ->setExecutionAmountLimit(1)
                ->setHandledMessageLimit(1),
        ];
    }
}
