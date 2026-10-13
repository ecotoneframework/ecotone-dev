<?php

namespace Test\Ecotone\Dbal\Fixture\DeadLetter\CustomConfiguration;

use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\Dbal\ExtensionObject\DbalDeadLetterBuilder;
use Ecotone\Api\ExtensionObject\ErrorHandlerConfiguration;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Api\ExtensionObject\RetryTemplateBuilder;

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
            ->withExecutionTimeLimitInMilliseconds(1000)
            ->withHandledMessageLimit(1)
            ->withErrorChannelName(self::ERROR_CHANNEL);
    }
}
