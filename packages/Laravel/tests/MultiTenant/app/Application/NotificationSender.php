<?php

declare(strict_types=1);

namespace App\MultiTenant\Application;

use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Modelling\Attribute\QueryHandler;

final class NotificationSender
{
    private array $notifications = [];

    public function sendWelcomeNotification(Customer $customer, #[Header('tenant')] $tenant): void
    {
        if (!isset($this->notifications[$tenant])) {
            $this->notifications[$tenant] = 0;
        }

        $this->notifications[$tenant]++;
    }

    #[QueryHandler("getNotificationsCount")]
    public function getNotifications(#[Header('tenant')] $tenant): int
    {
        return $this->notifications[$tenant] ?? 0;
    }
}