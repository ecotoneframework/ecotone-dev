<?php

declare(strict_types=1);

namespace Monorepo\ExampleApp\Common\Domain\Notification;

interface NotificationSender
{
    public function send(object $notification): void;
}