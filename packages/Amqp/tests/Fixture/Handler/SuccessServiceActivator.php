<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Fixture\Handler;

use Ecotone\Api\Asynchronous;
use Ecotone\Api\InternalHandler;
use Ecotone\Messaging\Message;

/**
 * licence Apache-2.0
 */
final class SuccessServiceActivator
{
    #[Asynchronous('async_channel')]
    #[InternalHandler('handle_channel')]
    public function handle(Message $message): void
    {
    }

    public function __toString()
    {
        return self::class;
    }
}
