<?php

namespace Test\Ecotone\Dbal\Fixture\DeadLetter\DeadLetterRightAway;

use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\Dbal\ExtensionObject\DbalDeadLetterBuilder;
use Ecotone\Api\ExtensionObject\PollingMetadata;

/**
 * licence Apache-2.0
 */
final class ErrorConfiguration
{
    #[ServiceContext]
    public function pollingConfiguration()
    {
        return PollingMetadata::create('orderService')
            ->withExecutionTimeLimitInMilliseconds(1000)
            ->withHandledMessageLimit(1)
            ->withErrorChannelName(DbalDeadLetterBuilder::STORE_CHANNEL);
    }
}
