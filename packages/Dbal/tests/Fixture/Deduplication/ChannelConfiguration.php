<?php

namespace Test\Ecotone\Dbal\Fixture\Deduplication;

use Ecotone\Api\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\DbalConfiguration;
use Ecotone\Api\PollingMetadata;
use Ecotone\Api\ServiceContext;

/**
 * licence Apache-2.0
 */
class ChannelConfiguration
{
    public const CHANNEL_NAME = 'processOrders';

    #[ServiceContext]
    public function registerCommandChannel(): array
    {
        return [
            DbalConfiguration::createWithDefaults()
                ->withDeduplication(true),
            DbalBackedMessageChannelBuilder::create(self::CHANNEL_NAME)
                ->withReceiveTimeout(1),
            PollingMetadata::create(self::CHANNEL_NAME)
                ->setHandledMessageLimit(10)
                ->setExecutionTimeLimitInMilliseconds(1000)
                ->setStopOnError(true),
        ];
    }
}
