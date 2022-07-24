<?php

namespace App\EventHandling;

use Ecotone\Modelling\Attribute\EventHandler;

class NotificationService
{
    #[EventHandler]
    public function notifyAboutNewOrder(OrderWasPlaced $event) : void
    {
        echo $event->getProductName() . "\n";
    }
}