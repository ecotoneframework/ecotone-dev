<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Part_2\Infrastructure\Messaging;

use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;

final class MessageChannelConfiguration
{
    const ASYNCHRONOUS_CHANNEL = "asynchronous";

    #[ServiceContext]
    public function asynchronousChannel()
    {
        /** This is in memory asynchronous channel. In Production run you would have RabbitMQ / Redis / SQS etc in here */
        return SimpleMessageChannelBuilder::createQueueChannel(self::ASYNCHRONOUS_CHANNEL);
    }
}