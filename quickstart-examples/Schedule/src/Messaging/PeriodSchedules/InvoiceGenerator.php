<?php

namespace App\Schedule\Messaging\PeriodSchedules;

use Ecotone\Api\Asynchronous;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\EventHandler;
use Ecotone\Api\CommandBus;
use Ecotone\Api\EventBus;

#[Asynchronous("invoicing")]
class InvoiceGenerator
{
    #[EventHandler(endpointId: "prepareInvoicing")]
    public function prepareInvoicing(UserWasRegistered $event, CommandBus $commandBus): void
    {
        echo "User was registered, setting up first invoice generation\n";
        $commandBus->send(new GenerateInvoice($event->personId), metadata: ["deliveryDelay" => 3000]);
    }

    #[CommandHandler(endpointId: "generateInvoice")]
    public function generateInvoice(GenerateInvoice $generateInvoice, EventBus $eventBus): void
    {
        echo "Invoice generated for user\n";

        $eventBus->publish(new InvoiceWasGenerated($generateInvoice->personId));
    }

    #[EventHandler(endpointId: "generateNextInvoice")]
    public function generateNextInvoice(InvoiceWasGenerated $event, CommandBus $commandBus): void
    {
        echo "Waiting to generate new invoice\n";

        $commandBus->send(new GenerateInvoice($event->personId), metadata: ["deliveryDelay" => 3000]);
    }
}