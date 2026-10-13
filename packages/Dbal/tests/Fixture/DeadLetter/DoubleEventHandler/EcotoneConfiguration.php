<?php

namespace Test\Ecotone\Dbal\Fixture\DeadLetter\DoubleEventHandler;

use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalDeadLetterBuilder;
use Ecotone\Api\ExtensionObject\PollingMetadata;

/**
 * licence Apache-2.0
 */
final class EcotoneConfiguration
{
    #[ServiceContext]
    public function pollingConfiguration()
    {
        return PollingMetadata::create('async')
            ->withExecutionTimeLimitInMilliseconds(1000)
            ->withHandledMessageLimit(1)
            ->withErrorChannelName(DbalDeadLetterBuilder::STORE_CHANNEL);
    }

    #[ServiceContext]
    public function channel()
    {
        return DbalBackedMessageChannelBuilder::create('async');
    }
}
