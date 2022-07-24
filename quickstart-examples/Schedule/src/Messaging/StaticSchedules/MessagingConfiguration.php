<?php

namespace App\Schedule\Messaging\StaticSchedules;

use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Messaging\Attribute\ServiceContext;

class MessagingConfiguration
{
    const CHANNEL_NAME = "notifications";

    #[ServiceContext]
    public function registerChannel()
    {
        return DbalBackedMessageChannelBuilder::create(self::CHANNEL_NAME);
    }
}