<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Stage_3\Infrastructure\Messaging;

use Ecotone\Api\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Api\ServiceContext;
use Ecotone\Api\SimpleMessageChannelBuilder;
use Ecotone\Api\ErrorHandlerConfiguration;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;
use Ecotone\Api\InstantRetryConfiguration;

final class MessageChannelConfiguration
{
    const ASYNCHRONOUS_CHANNEL = "asynchronous";

    #[ServiceContext]
    public function asynchronousChannel()
    {
        /**
         * This is dbal asynchronous channel (ecotone/dbal), which provides us with Outbox Pattern.
         * https://docs.ecotone.tech/modelling/asynchronous-handling
         */
        return DbalBackedMessageChannelBuilder::create(self::ASYNCHRONOUS_CHANNEL);
    }

    #[ServiceContext]
    public function asynchronousErrorHandling()
    {
        /**
         * This provides retries and storage into dbal dead letter
         * You may try it by throwing exception from Asynchronous Event Handler
         */
        return ErrorHandlerConfiguration::createWithDeadLetterChannel(
            "errorChannel",
            RetryTemplateBuilder::fixedBackOff(10)
                ->maxRetryAttempts(2),
            "dbal_dead_letter"
        );
    }

    #[ServiceContext]
    public function retryStrategy()
    {
        /**
         * This provides instant retries for Command Bus
         * You may try it by throwing exception from Synchronous Command Handler
         */
        return InstantRetryConfiguration::createWithDefaults()
            ->withCommandBusRetry(
                true, // is enabled
                3, // max retries
                [] // list of exceptions to be retried, leave empty if all should be retried
            );
    }
}