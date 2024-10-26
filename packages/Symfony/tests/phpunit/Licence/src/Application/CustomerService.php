<?php

declare(strict_types=1);

namespace Symfony\App\Licence\Application;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;

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
