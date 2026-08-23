<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Fixture\ErrorChannel;

use Ecotone\Amqp\Api\ExtensionObject\AmqpBackedMessageChannelBuilder;
use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Api\ExtensionObject\ErrorHandlerConfiguration;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;

/**
 * licence Apache-2.0
 */
class ErrorConfigurationContext
{
    public const INPUT_CHANNEL = 'correctOrders';
    public const ERROR_CHANNEL = 'errorChannel';


    #[ServiceContext]
    public function getChannels()
    {
        return [
            AmqpBackedMessageChannelBuilder::create(self::INPUT_CHANNEL)
                ->withReceiveTimeout(1),
        ];
    }

    #[ServiceContext]
    public function errorConfiguration()
    {
        return ErrorHandlerConfiguration::create(
            self::ERROR_CHANNEL,
            RetryTemplateBuilder::exponentialBackoff(1, 1)
                ->maxRetryAttempts(2)
        );
    }

    #[ServiceContext]
    public function pollingConfiguration()
    {
        return [
            PollingMetadata::create(self::INPUT_CHANNEL)
                ->setExecutionTimeLimitInMilliseconds(3000)
                ->setHandledMessageLimit(1)
                ->setErrorChannelName(self::ERROR_CHANNEL),
        ];
    }
}
