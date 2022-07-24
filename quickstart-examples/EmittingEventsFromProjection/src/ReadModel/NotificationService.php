<?php

namespace App\ReadModel;

use App\ReadModel\WalletBalance\WalletBalanceWasChanged;
use Ecotone\Modelling\Attribute\EventHandler;

final class NotificationService
{
    #[EventHandler]
    public function when(WalletBalanceWasChanged $event): void
    {
        // we could for example send websocket message here
        echo sprintf("Balance after change is  %s\n", $event->currentBalance);
    }
}