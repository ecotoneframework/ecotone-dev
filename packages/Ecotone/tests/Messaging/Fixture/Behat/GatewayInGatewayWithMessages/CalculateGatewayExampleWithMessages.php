<?php

namespace Test\Ecotone\Messaging\Fixture\Behat\GatewayInGatewayWithMessages;

use Ecotone\Api\MessageGateway;
use Ecotone\Messaging\Message;

/**
 * licence Apache-2.0
 */
interface CalculateGatewayExampleWithMessages
{
    #[MessageGateway(SomeQueryHandler::CALCULATE, requiredInterceptorNames: [InterceptorExample::class])]
    public function calculate(Message $amount): Message;
}
