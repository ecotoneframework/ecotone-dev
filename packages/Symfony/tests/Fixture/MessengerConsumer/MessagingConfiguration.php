<?php

declare(strict_types=1);

namespace Fixture\MessengerConsumer;

use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\SymfonyBundle\Api\ExtensionObject\SymfonyMessengerMessageChannelBuilder;

/**
 * licence Apache-2.0
 */
final class MessagingConfiguration
{
    #[ServiceContext]
    public function inMemoryAsyncChannel()
    {
        return SymfonyMessengerMessageChannelBuilder::create('messenger_async');
    }
}
