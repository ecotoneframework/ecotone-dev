<?php

declare(strict_types=1);

namespace Fixture\MessengerConsumer;

use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\SymfonyBundle\Messenger\SymfonyMessengerMessageChannelBuilder;

/**
 * licence Apache-2.0
 */
final class AmqpMessagingConfiguration
{
    #[ServiceContext]
    public function amqpAsyncChannel()
    {
        return SymfonyMessengerMessageChannelBuilder::create('amqp_async');
    }
}
