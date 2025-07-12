<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Fixture\AsynchronousMessageHandler;

use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Endpoint\PollingMetadata;

/**
 * @internal
 * licence Apache-2.0
 */
final class MessagingConfiguration
{
    #[ServiceContext]
    public function asyncChannel(): array
    {
        return [
            SimpleMessageChannelBuilder::createQueueChannel('async_channel'),
            PollingMetadata::create('async_channel')
                ->withTestingSetup(maxExecutionTimeInMilliseconds: 1000),
        ];
    }
}
