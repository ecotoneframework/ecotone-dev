<?php

namespace App\OutboxPattern\Application;

use App\OutboxPattern\Domain\OrderWasPlaced;
use App\OutboxPattern\Infrastructure\Configuration;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\EventHandler;

class EventHandlerService
{
    #[Asynchronous(Configuration::DATABASE_CHANNEL)]
    #[EventHandler(endpointId:"notifyAboutNeworder")]
    public function notifyAboutNewOrder(OrderWasPlaced $event) : void
    {
        echo "Order was placed: " . $event->getProductName() . "\n";
    }

    /**
     * This way we can keep outbox pattern by passing this Event first using Dbal Channel.
     * Then, we use External Broker Channel, from which it will be consumed for executing this Handler.
     * This solution is useful when we don't want to scale consumers that fetch messages from database
     */
    #[Asynchronous([Configuration::DATABASE_CHANNEL, Configuration::EXTERNAL_BROKER_CHANNEL])]
    #[EventHandler(endpointId:"heavyLifting")]
    public function doSomeHeavyLifting(OrderWasPlaced $event) : void
    {
        echo "External Broker Consumer: Order was placed: " . $event->getProductName() . "\n";
    }
}