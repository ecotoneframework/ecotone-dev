<?php

namespace Test\Ecotone\Modelling\Fixture\TwoAsynchronousSagas;

use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Channel\SimpleMessageChannelWithSerializationBuilder;
use Ecotone\Messaging\Endpoint\PollingMetadata;

class MessagingConfiguration
{
    public const ASYNCHRONOUS_CHANNEL = 'asynchronous_channel';

    #[ServiceContext]
    public function polling()
    {
        return [
            PollingMetadata::create(self::ASYNCHRONOUS_CHANNEL)
                ->withTestingSetup(),
        ];
    }

    #[ServiceContext]
    public function asynchronous()
    {
        return SimpleMessageChannelWithSerializationBuilder::createQueueChannel(self::ASYNCHRONOUS_CHANNEL);
    }
}
