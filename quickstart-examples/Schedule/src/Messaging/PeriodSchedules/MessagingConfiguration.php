<?php

namespace App\Schedule\Messaging\PeriodSchedules;

use Ecotone\Api\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Api\ServiceContext;

class MessagingConfiguration
{
    const CHANNEL_NAME = "invoicing";

    #[ServiceContext]
    public function registerChannel()
    {
        return DbalBackedMessageChannelBuilder::create(self::CHANNEL_NAME);
    }
}