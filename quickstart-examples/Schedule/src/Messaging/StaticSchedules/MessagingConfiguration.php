<?php

namespace App\Schedule\Messaging\StaticSchedules;

use Ecotone\Dbal\Api\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Attribute\ServiceContext;

class MessagingConfiguration
{
    const CHANNEL_NAME = "notifications";

    #[ServiceContext]
    public function registerChannel()
    {
        return DbalBackedMessageChannelBuilder::create(self::CHANNEL_NAME);
    }
}