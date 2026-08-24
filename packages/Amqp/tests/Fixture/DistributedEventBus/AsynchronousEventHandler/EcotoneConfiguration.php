<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Fixture\DistributedEventBus\AsynchronousEventHandler;

use Ecotone\Amqp\Api\ExtensionObject\AmqpBackedMessageChannelBuilder;
use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\ExtensionObject\PollingMetadata;
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
                ->withTestingSetup(maxExecutionTimeInMilliseconds: 1000),
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
