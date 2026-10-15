<?php

declare(strict_types=1);

namespace Fixture\Order;

use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\Laravel\LaravelQueueMessageChannelBuilder;

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
