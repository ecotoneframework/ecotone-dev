<?php

namespace Test\Ecotone\Dbal\Fixture\DeadLetter\DoubleEventHandler;

use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Dbal\Api\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Dbal\Api\ExtensionObject\DbalDeadLetterBuilder;

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
