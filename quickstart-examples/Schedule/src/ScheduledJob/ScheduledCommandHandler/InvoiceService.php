<?php

namespace App\Schedule\ScheduledJob\ScheduledCommandHandler;

use Ecotone\Api\Attribute\Poller;
use Ecotone\Api\Attribute\Scheduled;
use Ecotone\Api\Attribute\CommandHandler;

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