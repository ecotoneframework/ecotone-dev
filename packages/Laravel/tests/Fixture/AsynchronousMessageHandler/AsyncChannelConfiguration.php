<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\Fixture\AsynchronousMessageHandler;

use Ecotone\Laravel\Queue\LaravelQueueMessageChannelBuilder;
use Ecotone\Messaging\Attribute\ServiceContext;

/**
 * licence Apache-2.0
 */
final class AsyncChannelConfiguration
{
    #[ServiceContext]
    public function asyncChannels(): array
    {
        return [
            LaravelQueueMessageChannelBuilder::create('async_channel'),
            LaravelQueueMessageChannelBuilder::create(queueName: 'asynchronous_queue', connectionName: 'database'),
        ];
    }
}
