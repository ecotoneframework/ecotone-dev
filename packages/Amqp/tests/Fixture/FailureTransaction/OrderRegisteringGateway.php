<?php

namespace Test\Ecotone\Amqp\Fixture\FailureTransaction;

use Ecotone\Messaging\Attribute\MessageGateway;

/**
 * licence Apache-2.0
 */
interface OrderRegisteringGateway
{
    #[MessageGateway('placeOrder')]
    public function place(string $order): void;
}
