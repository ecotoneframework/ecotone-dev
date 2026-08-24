<?php

namespace App\Schedule\Messaging\StaticSchedules;

use Ecotone\Api\Asynchronous;
use Ecotone\Api\Delayed;
use Ecotone\Api\Reference;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\EventHandler;
use Ecotone\Api\EventBus;

class WelcomeNotification
{
    #[CommandHandler("registerUser")]
    public function registerUser(#[Reference] EventBus $eventBus): void
    {
        // register user
        $eventBus->publish(new UserWasRegistered(100));
    }

    #[Asynchronous(MessagingConfiguration::CHANNEL_NAME)]
    #[Delayed(3000)]
    #[EventHandler(endpointId: "welcomeEmail")]
    public function sendWelcomeEmailWhen(UserWasRegistered $event): void
    {
        echo sprintf("Welcome Notification sent after 3 seconds for user with id %s\n", $event->userId);
    }
}