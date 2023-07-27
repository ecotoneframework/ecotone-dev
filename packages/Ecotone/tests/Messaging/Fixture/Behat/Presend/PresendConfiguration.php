<?php

namespace Test\Ecotone\Messaging\Fixture\Behat\Presend;

use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Channel\SimpleMessageChannelWithSerializationBuilder;
use Ecotone\Messaging\Endpoint\PollingMetadata;

class PresendConfiguration
{
    #[ServiceContext]
    public function shopBuyConfiguration()
    {
        return [
            SimpleMessageChannelWithSerializationBuilder::createQueueChannel('shop'),
            PollingMetadata::create('shop')
                ->setExecutionAmountLimit(1)
                ->setHandledMessageLimit(1),
        ];
    }
}
