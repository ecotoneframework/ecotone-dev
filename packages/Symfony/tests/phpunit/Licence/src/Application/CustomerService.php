<?php

declare(strict_types=1);

namespace Symfony\App\Licence\Application;

use Ecotone\Api\Asynchronous;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\QueryHandler;

/**
 * licence Enterprise
 */
final class CustomerService
{
    private int $notifications = 0;

    #[QueryHandler('getNotifications')]
    public function getNotifications(): int
    {
        return $this->notifications;
    }

    #[Asynchronous('notifications')]
    #[CommandHandler('sendNotification', endpointId: 'notificationSender')]
    public function sendNotification(
    ) {
        $this->notifications++;
    }
}
