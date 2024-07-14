<?php

namespace Test\Ecotone\Dbal\Fixture\AsynchronousChannelTransaction;

use Ecotone\Messaging\Attribute\MessageGateway;

/**
 * licence Apache-2.0
 */
interface OrderRegisteringGateway
{
    #[MessageGateway('placeOrder')]
    public function place(string $order): void;
}
