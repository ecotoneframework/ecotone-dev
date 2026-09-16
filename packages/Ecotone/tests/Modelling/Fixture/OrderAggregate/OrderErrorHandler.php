<?php

namespace Test\Ecotone\Modelling\Fixture\OrderAggregate;

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
