<?php

namespace App\Schedule\ScheduledJob\ScheduledJob;

use Ecotone\Messaging\Attribute\Poller;
use Ecotone\Messaging\Attribute\Scheduled;

class NotificationService
{
    const NAME = "notificationSender";

    #[Scheduled(endpointId: self::NAME)]
    #[Poller(fixedRateInMilliseconds: 1000)]
    public function sendNotifications(): void
    {
        echo "Sending notifications...\n";
    }
}