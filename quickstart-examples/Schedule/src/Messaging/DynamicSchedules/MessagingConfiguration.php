<?php

namespace App\Schedule\Messaging\DynamicSchedules;

use Ecotone\Api\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Api\ServiceContext;

class MessagingConfiguration
{
    const CHANNEL_NAME = "orders";

    #[ServiceContext]
    public function registerChannel()
    {
        return DbalBackedMessageChannelBuilder::create(self::CHANNEL_NAME);
    }
}