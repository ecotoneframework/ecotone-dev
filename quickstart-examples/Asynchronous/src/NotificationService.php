<?php

namespace App\Asynchronous;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\EventHandler;

class NotificationService
{
    const ASYNCHRONOUS_MESSAGES = "asynchronous_messages";

    #[Asynchronous(self::ASYNCHRONOUS_MESSAGES)]
    #[EventHandler(endpointId:"notifyAboutNeworder")]
    public function notifyAboutNewOrder(OrderWasPlaced $event) : void
    {
        echo "Handling asynchronously: " . $event->getProductName() . "\n";
    }
}