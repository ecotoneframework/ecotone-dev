<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\Handler\ErrorChannelAsyncMisplaced;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\ErrorChannel;
use Ecotone\Modelling\Attribute\CommandHandler;

/**
 * licence Enterprise
 *
 * Wrong placement: #[ErrorChannel] is on the handler method instead of inside #[Asynchronous(asynchronousExecution: [...])].
 * The framework must reject this configuration with a descriptive error.
 */
final class AsyncHandlerWithErrorChannelDirectlyOnMethod
{
    public const ASYNC_CHANNEL = 'asyncMisplacedErrorChannel';
    public const ROUTING_KEY = 'misplaced.errorchannel';

    #[Asynchronous(self::ASYNC_CHANNEL)]
    #[ErrorChannel('someErrorChannel')]
    #[CommandHandler(self::ROUTING_KEY, 'misplacedErrorChannelHandler')]
    public function handle(string $payload): void
    {
    }
}
