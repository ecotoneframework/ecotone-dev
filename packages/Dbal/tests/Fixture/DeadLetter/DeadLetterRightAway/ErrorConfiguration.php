<?php

namespace Test\Ecotone\Dbal\Fixture\DeadLetter\DeadLetterRightAway;

use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Dbal\Api\ExtensionObject\DbalDeadLetterBuilder;

/**
 * licence Apache-2.0
 */
final class ErrorConfiguration
{
    #[ServiceContext]
    public function pollingConfiguration()
    {
        return PollingMetadata::create('orderService')
            ->setExecutionTimeLimitInMilliseconds(1000)
            ->setHandledMessageLimit(1)
            ->setErrorChannelName(DbalDeadLetterBuilder::STORE_CHANNEL);
    }
}
