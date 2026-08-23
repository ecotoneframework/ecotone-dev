<?php

namespace App\Schedule\Messaging\StaticSchedules;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\Endpoint\Delayed;
use Ecotone\Api\Attribute\Parameter\Reference;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Gateway\EventBus;

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