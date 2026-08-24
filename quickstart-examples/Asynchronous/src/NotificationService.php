<?php

namespace App\Asynchronous;

use Ecotone\Api\Asynchronous;
use Ecotone\Api\EventHandler;

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