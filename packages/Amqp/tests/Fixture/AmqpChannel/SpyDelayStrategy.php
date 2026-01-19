<?php

namespace Test\Ecotone\Amqp\Fixture\AmqpChannel;

use Ecotone\Amqp\HeadersExchangeDelayStrategy;
use Enqueue\AmqpTools\DelayStrategy;
use Interop\Amqp\AmqpContext;
use Interop\Amqp\AmqpDestination;
use Interop\Amqp\AmqpMessage;

class SpyDelayStrategy implements DelayStrategy
{
    public static bool $wasCalled = false;

    public function __construct()
    {
        self::$wasCalled = false;
    }

    public function delayMessage(AmqpContext $context, AmqpDestination $dest, AmqpMessage $message, int $delay): void
    {
        self::$wasCalled = true;
        (new HeadersExchangeDelayStrategy())->delayMessage($context, $dest, $message, $delay);
    }
}