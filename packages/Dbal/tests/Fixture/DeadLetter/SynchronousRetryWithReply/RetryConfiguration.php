<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousRetryWithReply;

use Ecotone\Api\Dbal\DbalDeadLetterBuilder;
use Ecotone\Api\ErrorHandlerConfiguration;
use Ecotone\Api\PollingMetadata;
use Ecotone\Api\ServiceContext;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;
use Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousExample\ErrorConfigurationContext;

/**
 * licence Enterprise
 */
class RetryConfiguration
{
    public const ERROR_CHANNEL = 'customErrorChannel';

    #[ServiceContext]
    public function errorConfiguration()
    {
        return ErrorHandlerConfiguration::createWithDeadLetterChannel(
            self::ERROR_CHANNEL,
            RetryTemplateBuilder::exponentialBackOff(100, 2)
                ->maxRetries(1),
            DbalDeadLetterBuilder::STORE_CHANNEL
        );
    }

    #[ServiceContext]
    public function pollingConfiguration()
    {
        return PollingMetadata::create(ErrorConfigurationContext::ASYNC_REPLY_CHANNEL)
            ->setExecutionTimeLimitInMilliseconds(1000)
            ->setHandledMessageLimit(1)
            ->setErrorChannelName(self::ERROR_CHANNEL);
    }
}
