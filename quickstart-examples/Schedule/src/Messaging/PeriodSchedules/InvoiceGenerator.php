<?php

namespace App\Schedule\Messaging\PeriodSchedules;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\EventBus;

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