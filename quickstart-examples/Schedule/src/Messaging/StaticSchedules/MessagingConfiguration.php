<?php

namespace App\Schedule\Messaging\StaticSchedules;

use Ecotone\Api\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Api\ServiceContext;

class MessagingConfiguration
{
    const CHANNEL_NAME = "notifications";

    #[ServiceContext]
    public function registerChannel()
    {
        return DbalBackedMessageChannelBuilder::create(self::CHANNEL_NAME);
    }
}