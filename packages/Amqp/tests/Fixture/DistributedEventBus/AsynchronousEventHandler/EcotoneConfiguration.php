<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Fixture\DistributedEventBus\AsynchronousEventHandler;

use Ecotone\Api\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Api\PollingMetadata;
use Ecotone\Api\ServiceContext;
use Ecotone\Messaging\Channel\PollableChannel\GlobalPollableChannelConfiguration;

/**
 * licence Apache-2.0
 */
final class EcotoneConfiguration
{
    #[ServiceContext]
    public function asyncChannel()
    {
        return [
            AmqpBackedMessageChannelBuilder::create('notification_channel'),
            PollingMetadata::create('notification_channel')
                ->withTestingSetup(executionTimeLimitInMilliseconds: 1000),
        ];
    }

    #[ServiceContext]
    public function messaging()
    {
        return [
            GlobalPollableChannelConfiguration::createWithDefaults()
                  ->withCollector(false),
        ];
    }
}
