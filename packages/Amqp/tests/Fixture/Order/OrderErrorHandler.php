<?php

namespace Test\Ecotone\Amqp\Fixture\Order;

use Ecotone\Api\InternalHandler;
use Ecotone\Messaging\MessagingException;

/**
 * licence Apache-2.0
 */
class OrderErrorHandler
{
    #[InternalHandler(ChannelConfiguration::ERROR_CHANNEL)]
    public function errorConfiguration(MessagingException $exception)
    {
        throw $exception;
    }
}
