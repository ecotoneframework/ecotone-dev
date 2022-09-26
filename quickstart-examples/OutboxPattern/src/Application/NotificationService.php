<?php

namespace App\OutboxPattern\Application;

use App\OutboxPattern\Domain\PlaceOrder;
use App\OutboxPattern\Infrastructure\Configuration;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\EventHandler;

class NotificationService
{
    #[Asynchronous(Configuration::ASYNCHRONOUS_CHANNEL)]
    #[EventHandler(endpointId:"notifyAboutNeworder")]
    public function notifyAboutNewOrder(PlaceOrder $event) : void
    {
        echo "Order was placed: " . $event->getProductName() . "\n";
    }
}