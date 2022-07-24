<?php

namespace App\Schedule\Messaging\DynamicSchedules;

use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Messaging\Attribute\ServiceContext;

class MessagingConfiguration
{
    const CHANNEL_NAME = "orders";

    #[ServiceContext]
    public function registerChannel()
    {
        return DbalBackedMessageChannelBuilder::create(self::CHANNEL_NAME);
    }
}