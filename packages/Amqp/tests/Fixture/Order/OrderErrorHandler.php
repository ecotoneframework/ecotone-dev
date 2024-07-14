<?php

namespace Test\Ecotone\Amqp\Fixture\Order;

use Ecotone\Messaging\Attribute\ServiceActivator;
use Ecotone\Messaging\MessagingException;

/**
 * licence Apache-2.0
 */
class OrderErrorHandler
{
    #[ServiceActivator(ChannelConfiguration::ERROR_CHANNEL)]
    public function errorConfiguration(MessagingException $exception)
    {
        throw $exception;
    }
}
