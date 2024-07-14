<?php

namespace Test\Ecotone\Dbal\Fixture\DeadLetter\DeadLetterRightAway;

use Ecotone\Dbal\Recoverability\DbalDeadLetterBuilder;
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Endpoint\PollingMetadata;

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
