<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\Fixture\ConnectionRetry;

use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Channel\ExceptionalQueueChannel;

/**
 * licence Apache-2.0
 */
final class ConnectionRetryChannels
{
    #[ServiceContext]
    public function channels(): array
    {
        return [
            SimpleMessageChannelBuilder::createExceptionChannel(ExceptionalQueueChannel::createWithExceptionOnReceive('connection_lost_four_times', 4)),
            SimpleMessageChannelBuilder::createExceptionChannel(ExceptionalQueueChannel::createWithExceptionOnReceive('connection_lost_five_times', 5)),
            PollingMetadata::create('connection_lost_four_times')->setStopOnError(false),
            PollingMetadata::create('connection_lost_five_times')->setStopOnError(false),
        ];
    }
}
