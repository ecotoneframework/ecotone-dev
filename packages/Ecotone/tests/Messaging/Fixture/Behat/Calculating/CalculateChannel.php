<?php

namespace Test\Ecotone\Messaging\Fixture\Behat\Calculating;

use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Channel\SimpleMessageChannelWithSerializationBuilder;

class CalculateChannel
{
    #[ServiceContext]
    public function configuration(): array
    {
        return [
            SimpleMessageChannelWithSerializationBuilder::createQueueChannel('resultChannel'),
        ];
    }
}
