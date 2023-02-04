<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Stage_1\Infrastructure;

use App\ReactiveSystem\Stage_1\Domain\Notification\NotificationSender;

final class StubNotificationSender implements NotificationSender
{
    public function send(object $notification): void
    {
        /** In production run, we would send email / sms here */
        echo "\n Sending Order Confirmation Notification! \n";
    }
}