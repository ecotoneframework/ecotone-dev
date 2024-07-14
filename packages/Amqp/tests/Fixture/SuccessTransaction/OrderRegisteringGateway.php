<?php

namespace Test\Ecotone\Amqp\Fixture\SuccessTransaction;

use Ecotone\Messaging\Attribute\MessageGateway;

/**
 * licence Apache-2.0
 */
interface OrderRegisteringGateway
{
    #[MessageGateway('placeOrder')]
    public function place(string $order): void;
}
