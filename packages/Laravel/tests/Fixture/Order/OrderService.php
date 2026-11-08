<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\Fixture\Order;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\EventHandler;

/**
 * licence Apache-2.0
 */
final class OrderService
{
    #[Asynchronous('asynchronous_queue')]
    #[EventHandler(endpointId: 'send_confirmation_email')]
    public function sendConfirmationEmail(OrderWasPlaced $event): void
    {
    }

    #[Asynchronous('asynchronous_queue')]
    #[EventHandler(endpointId: 'deliver_order')]
    public function deliverOrder(OrderWasPlaced $event): void
    {
    }
}
