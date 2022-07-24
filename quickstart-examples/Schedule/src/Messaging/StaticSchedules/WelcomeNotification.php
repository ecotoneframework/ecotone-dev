<?php

namespace App\Schedule\Messaging\StaticSchedules;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\Endpoint\Delayed;
use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\EventBus;

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