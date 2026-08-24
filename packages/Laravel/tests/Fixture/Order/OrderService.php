<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\Fixture\Order;

use Ecotone\Api\Asynchronous;
use Ecotone\Api\EventHandler;

/**
 * licence Apache-2.0
 */
final class OrderService
{
    #[Asynchronous('asynchronous_queue')]
    #[EventHandler(endpointId: 'send_confirmation_email')]
    public function sendConfirmationEmail(OrderWasPlaced $event): void
    {
        echo "Sending confirmation email\n";
    }

    #[Asynchronous('asynchronous_queue')]
    #[EventHandler(endpointId: 'deliver_order')]
    public function deliverOrder(OrderWasPlaced $event): void
    {
        echo "Deliver order\n";
    }
}
