<?php

namespace App\EventHandling;

use Ecotone\Api\EventHandler;

class NotificationService
{
    #[EventHandler]
    public function notifyAboutNewOrder(OrderWasPlaced $event) : void
    {
        echo $event->getProductName() . "\n";
    }
}