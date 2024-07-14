<?php

namespace Test\Ecotone\Dbal\Fixture\DeadLetter\Example;

use Ecotone\Messaging\Attribute\MessageGateway;

/**
 * licence Apache-2.0
 */
interface OrderGateway
{
    #[MessageGateway(ErrorConfigurationContext::INPUT_CHANNEL)]
    public function order(string $type): void;

    #[MessageGateway('getOrderAmount')]
    public function getOrderAmount(): int;
}
