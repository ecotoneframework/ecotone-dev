<?php

declare(strict_types=1);

namespace App\MultiTenant\Application;

use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Modelling\Attribute\QueryHandler;

final class NotificationSender
{
    private static array $notifications = [];

    public function sendWelcomeNotification(Customer $customer, #[Header('tenant')] $tenant): void
    {
        if (!isset(self::$notifications[$tenant])) {
            self::$notifications[$tenant] = 0;
        }

        self::$notifications[$tenant]++;
        echo "Sending welcome notification to customer {$customer->getCustomerId()} for tenant {$tenant}\n";
    }

    #[QueryHandler("getNotificationsCount")]
    public function getNotifications(#[Header('tenant')] $tenant): int
    {
        return self::$notifications[$tenant] ?? 0;
    }
}