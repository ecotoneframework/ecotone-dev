<?php

namespace App\Schedule\ScheduledJob\ScheduledCommandHandler;

use Ecotone\Messaging\Attribute\Poller;
use Ecotone\Messaging\Attribute\Scheduled;
use Ecotone\Modelling\Attribute\CommandHandler;

class InvoiceService
{
    const NAME = "invoiceGenerator";

    #[Scheduled("generateInvoices", self::NAME)]
    #[Poller(cron: "* * * * *")]
    public function fetchUsersToInvoice(): array
    {
//        fetch from database
        return [rand(1, 100),rand(101, 200)];
    }

    #[CommandHandler("generateInvoices")]
    public function generateInvoicesFor(array $userIds): void
    {
        foreach ($userIds as $userId) {
            echo sprintf("Invoice generated for %s\n", $userId);
        }
    }
}