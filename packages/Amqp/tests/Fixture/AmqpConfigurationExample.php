<?php

namespace Test\Ecotone\Amqp\Fixture;

use Ecotone\Messaging\SubscribableChannel;

/**
 * Interface AmqpConfigurationExample
 * @package Test\Ecotone\Amqp\Fixture
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
interface AmqpConfigurationExample
{
    /**
     * @return SubscribableChannel
     */
    public function test(): SubscribableChannel;
}
