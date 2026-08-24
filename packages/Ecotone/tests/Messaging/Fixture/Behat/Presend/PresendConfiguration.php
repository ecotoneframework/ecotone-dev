<?php

namespace Test\Ecotone\Messaging\Fixture\Behat\Presend;

use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
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
