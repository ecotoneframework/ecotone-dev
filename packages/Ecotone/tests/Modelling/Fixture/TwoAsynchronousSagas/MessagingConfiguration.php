<?php

namespace Test\Ecotone\Modelling\Fixture\TwoAsynchronousSagas;

use Ecotone\Api\PollingMetadata;
use Ecotone\Api\ServiceContext;
use Ecotone\Api\SimpleMessageChannelBuilder;

/**
 * licence Apache-2.0
 */
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
        return SimpleMessageChannelBuilder::createQueueChannel(self::ASYNCHRONOUS_CHANNEL);
    }
}
