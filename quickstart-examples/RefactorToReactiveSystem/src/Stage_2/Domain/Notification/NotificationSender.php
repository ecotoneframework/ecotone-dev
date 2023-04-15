<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Stage_2\Domain\Notification;

interface NotificationSender
{
    public function send(object $notification): void;
}