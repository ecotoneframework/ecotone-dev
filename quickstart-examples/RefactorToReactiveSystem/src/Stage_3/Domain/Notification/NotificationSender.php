<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Stage_3\Domain\Notification;

interface NotificationSender
{
    public function send(object $notification): void;
}