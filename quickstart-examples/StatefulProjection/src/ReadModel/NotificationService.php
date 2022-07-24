<?php

namespace App\ReadModel;

use App\ReadModel\TicketCounterProjection\TicketCounterWasChanged;
use Ecotone\Modelling\Attribute\EventHandler;

final class NotificationService
{
    #[EventHandler]
    public function when(TicketCounterWasChanged $event): void
    {
        // we could for example send websocket message here
        echo sprintf("Current count of tickets is %d\n", $event->currentAmount);
    }
}