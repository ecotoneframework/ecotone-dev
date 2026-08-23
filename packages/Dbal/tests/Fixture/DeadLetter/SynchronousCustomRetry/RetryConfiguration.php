<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousCustomRetry;

use Ecotone\Dbal\Api\ExtensionObject\DbalDeadLetterBuilder;
use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\ExtensionObject\ErrorHandlerConfiguration;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;

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
}
