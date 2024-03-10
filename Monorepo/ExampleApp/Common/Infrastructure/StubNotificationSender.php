<?php

declare(strict_types=1);

namespace Monorepo\ExampleApp\Common\Infrastructure;

use Monorepo\ExampleApp\Common\Domain\Notification\NotificationSender;
use Monorepo\ExampleApp\Common\Domain\Notification\OrderConfirmationNotification;

final class StubNotificationSender implements NotificationSender
{
    public function __construct(private Output $output, private Configuration $configuration)
    {}

    public function send(OrderConfirmationNotification $notification): void
    {
        /** In production run, we would send email / sms here */

        if ($notification->orderId->equals($this->configuration->failToNotifyOrder())) {
            throw new \InvalidArgumentException("Failure while sending an notification.");
        }

        $this->output->write("Sending Order Confirmation Notification!");
    }
}