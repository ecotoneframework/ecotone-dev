<?php

namespace Test\Ecotone\Dbal\Fixture\DeadLetter\CustomConfiguration;

use Ecotone\Dbal\Recoverability\DbalDeadLetterBuilder;
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Handler\Recoverability\ErrorHandlerConfiguration;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;
use Test\Ecotone\Dbal\Fixture\DeadLetter\Example\ErrorConfigurationContext;

final class ErrorConfiguration
{
    #[ServiceContext]
    public function errorConfiguration()
    {
        return ErrorHandlerConfiguration::createWithDeadLetterChannel(
            ErrorConfigurationContext::ERROR_CHANNEL,
            RetryTemplateBuilder::exponentialBackoff(1, 1)
                ->maxRetryAttempts(1),
            DbalDeadLetterBuilder::STORE_CHANNEL
        );
    }
}
