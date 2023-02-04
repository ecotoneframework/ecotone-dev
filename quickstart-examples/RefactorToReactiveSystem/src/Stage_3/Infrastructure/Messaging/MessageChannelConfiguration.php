<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Stage_3\Infrastructure\Messaging;

use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Handler\Recoverability\ErrorHandlerConfiguration;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;

final class MessageChannelConfiguration
{
    const ASYNCHRONOUS_CHANNEL = "asynchronous";

    #[ServiceContext]
    public function asynchronousChannel()
    {
        /**
         * This is in memory asynchronous channel. In Production run you would have RabbitMQ / Redis / SQS etc in here:
         * https://docs.ecotone.tech/modelling/asynchronous-handling
         */
        return SimpleMessageChannelBuilder::createQueueChannel(self::ASYNCHRONOUS_CHANNEL);
    }

    #[ServiceContext]
    public function errorHandling()
    {
        return ErrorHandlerConfiguration::createWithDeadLetterChannel(
            "errorChannel",
            RetryTemplateBuilder::exponentialBackoff(1000, 10)
                ->maxRetryAttempts(3),
            "finalErrorChannel"
        );
    }
}