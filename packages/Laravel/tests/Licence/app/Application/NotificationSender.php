<?php

declare(strict_types=1);

namespace App\Licence\Laravel\Application;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;

/**
 * licence Enterprise
 */
final class NotificationSender
{
    private int $notifications = 0;

    #[Asynchronous('asynchronous')]
    #[CommandHandler('sendNotification', endpointId: 'sendNotificationEndpoint')]
    public function sendWelcomeNotification(): void
    {
        $this->notifications++;
    }

    #[QueryHandler('getNotificationsCount')]
    public function getNotifications(): int
    {
        return $this->notifications;
    }
}
