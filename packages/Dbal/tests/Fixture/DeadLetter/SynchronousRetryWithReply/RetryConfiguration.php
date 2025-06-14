<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousRetryWithReply;

use Ecotone\Dbal\Recoverability\DbalDeadLetterBuilder;
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Handler\Recoverability\ErrorHandlerConfiguration;
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
            RetryTemplateBuilder::exponentialBackoff(100, 2)
                ->maxRetryAttempts(1),
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
