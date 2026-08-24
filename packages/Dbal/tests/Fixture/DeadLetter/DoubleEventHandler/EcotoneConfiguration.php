<?php

namespace Test\Ecotone\Dbal\Fixture\DeadLetter\DoubleEventHandler;

use Ecotone\Api\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\DbalDeadLetterBuilder;
use Ecotone\Api\PollingMetadata;
use Ecotone\Api\ServiceContext;

/**
 * licence Apache-2.0
 */
final class EcotoneConfiguration
{
    #[ServiceContext]
    public function pollingConfiguration()
    {
        return PollingMetadata::create('async')
            ->setExecutionTimeLimitInMilliseconds(1000)
            ->setHandledMessageLimit(1)
            ->setErrorChannelName(DbalDeadLetterBuilder::STORE_CHANNEL);
    }

    #[ServiceContext]
    public function channel()
    {
        return DbalBackedMessageChannelBuilder::create('async');
    }
}
