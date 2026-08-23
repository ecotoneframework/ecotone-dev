<?php

namespace Test\Ecotone\Amqp\Fixture\DistributedDeadLetter\Receiver;

use Ecotone\Amqp\Api\ExtensionObject\AmqpBackedMessageChannelBuilder;
use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Api\ExtensionObject\ErrorHandlerConfiguration;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;

/**
 * licence Apache-2.0
 */
class TicketServiceMessagingConfiguration
{
    public const SERVICE_NAME = 'ticket_service';
    public const ERROR_CHANNEL = 'error_channel';
    public const DEAD_LETTER_CHANNEL = 'dead_letter';

    #[ServiceContext]
    public function configure()
    {
        return [
            AmqpBackedMessageChannelBuilder::create(self::SERVICE_NAME),
            PollingMetadata::create(self::SERVICE_NAME)
                ->setHandledMessageLimit(1)
                ->setExecutionTimeLimitInMilliseconds(5000)
                ->setErrorChannelName(self::ERROR_CHANNEL),
        ];
    }

    #[ServiceContext]
    public function errorConfiguration(): ErrorHandlerConfiguration
    {
        return ErrorHandlerConfiguration::createWithDeadLetterChannel(
            self::ERROR_CHANNEL,
            RetryTemplateBuilder::fixedBackOff(1)
                ->maxRetryAttempts(1),
            self::DEAD_LETTER_CHANNEL
        );
    }
}
