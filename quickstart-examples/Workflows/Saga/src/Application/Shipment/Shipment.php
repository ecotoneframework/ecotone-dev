<?php

declare(strict_types=1);

namespace App\Workflow\Saga\Application\Shipment;

use App\Workflow\Saga\Application\Shipment\Command\ShipOrder;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\CommandHandler;

final readonly class Shipment
{
    #[Asynchronous('async')]
    #[CommandHandler]
    public function shipOrder(ShipOrder $command): void
    {
        // Calling some external service to ship the order
    }
}