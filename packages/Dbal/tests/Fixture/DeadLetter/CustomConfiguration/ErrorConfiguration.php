<?php

namespace Test\Ecotone\Dbal\Fixture\DeadLetter\CustomConfiguration;

use Ecotone\Api\Dbal\DbalDeadLetterBuilder;
use Ecotone\Api\ErrorHandlerConfiguration;
use Ecotone\Api\PollingMetadata;
use Ecotone\Api\ServiceContext;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;

/**
 * licence Apache-2.0
 */
final class ErrorConfiguration
{
    public const ERROR_CHANNEL = 'errorChannel';

    #[ServiceContext]
    public function errorConfiguration()
    {
        return ErrorHandlerConfiguration::createWithDeadLetterChannel(
            self::ERROR_CHANNEL,
            RetryTemplateBuilder::exponentialBackOff(1, 1)
                ->maxRetries(1),
            DbalDeadLetterBuilder::STORE_CHANNEL
        );
    }

    #[ServiceContext]
    public function pollingConfiguration()
    {
        return PollingMetadata::create('orderService')
            ->setExecutionTimeLimitInMilliseconds(1000)
            ->setHandledMessageLimit(1)
            ->setErrorChannelName(self::ERROR_CHANNEL);
    }
}
