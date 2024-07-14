<?php

namespace Test\Ecotone\Amqp\Fixture\FailureTransactionWithFatalError;

use Ecotone\Messaging\Attribute\MessageGateway;

/**
 * licence Apache-2.0
 */
interface OrderRegisteringGateway
{
    /**
     * @MessageGateway(requestChannel="placeOrder")
     */
    public function place(string $order): void;
}
