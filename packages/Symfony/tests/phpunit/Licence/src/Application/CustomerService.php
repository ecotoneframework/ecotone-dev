<?php

declare(strict_types=1);

namespace Symfony\App\Licence\Application;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\QueryHandler;

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
