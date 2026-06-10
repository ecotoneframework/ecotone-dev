<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Fixture\AsyncQueue;

use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;

/**
 * licence Apache-2.0
 */
final class AsyncQueueChannelConfiguration
{
    #[ServiceContext]
    public function registerAsyncQueue(): SimpleMessageChannelBuilder
    {
        return SimpleMessageChannelBuilder::createQueueChannel('ecotone_test_queue');
    }
}
