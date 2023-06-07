<?php

declare(strict_types=1);

namespace Monorepo\ExampleApp\Common\Infrastructure;

use Monorepo\ExampleApp\Common\Domain\Notification\NotificationSender;

final class StubNotificationSender implements NotificationSender
{
    public function __construct(private Output $output)
    {}

    public function send(object $notification): void
    {
        /** In production run, we would send email / sms here */
        $this->output->write("Sending Order Confirmation Notification!");
    }
}