<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Fixture\AsyncQueue;

use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;

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
