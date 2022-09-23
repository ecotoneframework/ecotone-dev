<?php

namespace Test\Ecotone\Dbal\Fixture\DeadLetter\DeadLetterRightAway;

use Ecotone\Dbal\Recoverability\DbalDeadLetterBuilder;
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Handler\Bridge\BridgeBuilder;
use Ecotone\Messaging\Handler\Recoverability\ErrorHandlerConfiguration;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;
use Test\Ecotone\Dbal\Fixture\DeadLetter\Example\ErrorConfigurationContext;

final class ErrorConfiguration
{
    #[ServiceContext]
    public function pollingConfiguration()
    {
        return PollingMetadata::create('orderService')
            ->setExecutionTimeLimitInMilliseconds(1)
            ->setHandledMessageLimit(1)
            ->setErrorChannelName(DbalDeadLetterBuilder::STORE_CHANNEL);
    }
}
