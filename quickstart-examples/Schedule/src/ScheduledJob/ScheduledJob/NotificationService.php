<?php

namespace App\Schedule\ScheduledJob\ScheduledJob;

use Ecotone\Api\Poller;
use Ecotone\Api\Scheduled;

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