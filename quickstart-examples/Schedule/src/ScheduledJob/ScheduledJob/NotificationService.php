<?php

namespace App\Schedule\ScheduledJob\ScheduledJob;

use Ecotone\Api\Attribute\Poller;
use Ecotone\Api\Attribute\Scheduled;

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