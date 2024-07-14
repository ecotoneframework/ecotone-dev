<?php

declare(strict_types=1);

namespace Fixture\Order;

use Ecotone\Laravel\Queue\LaravelQueueMessageChannelBuilder;
use Ecotone\Messaging\Attribute\ServiceContext;

/**
 * licence Apache-2.0
 */
final class MessagingConfig
{
    #[ServiceContext]
    public function asynchronousQueue()
    {
        return LaravelQueueMessageChannelBuilder::create(
            queueName: 'asynchronous_queue',
            connectionName: 'database'
        );
    }
}
