<?php

declare(strict_types=1);

namespace App\Testing\Infrastructure;

use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Channel\MessageChannelBuilder;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;

final class MessagingConfiguration
{
    const ASYNCHRONOUS_MESSAGES = "asynchronous_messages_channel";

    #[ServiceContext]
    public function registerInMemoryPollableChannel() : MessageChannelBuilder
    {
        return SimpleMessageChannelBuilder::createQueueChannel(self::ASYNCHRONOUS_MESSAGES, true);
    }
}