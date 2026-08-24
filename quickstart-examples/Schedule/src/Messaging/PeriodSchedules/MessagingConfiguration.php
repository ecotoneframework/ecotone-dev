<?php

namespace App\Schedule\Messaging\PeriodSchedules;

use Ecotone\Dbal\Api\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Attribute\ServiceContext;

class MessagingConfiguration
{
    const CHANNEL_NAME = "invoicing";

    #[ServiceContext]
    public function registerChannel()
    {
        return DbalBackedMessageChannelBuilder::create(self::CHANNEL_NAME);
    }
}