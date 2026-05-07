<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\Handler\ErrorChannelAsyncMisplaced;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\DelayedRetry;
use Ecotone\Modelling\Attribute\CommandHandler;

/**
 * licence Enterprise
 *
 * Wrong placement: #[DelayedRetry] is on the handler method instead of inside #[Asynchronous(asynchronousExecution: [...])].
 * The framework must reject this configuration with a descriptive error.
 */
final class AsyncHandlerWithDelayedRetryDirectlyOnMethod
{
    public const ASYNC_CHANNEL = 'asyncMisplacedDelayedRetry';
    public const ROUTING_KEY = 'misplaced.delayedretry';

    #[Asynchronous(self::ASYNC_CHANNEL)]
    #[DelayedRetry(initialDelayMs: 1, maxAttempts: 2)]
    #[CommandHandler(self::ROUTING_KEY, 'misplacedDelayedRetryHandler')]
    public function handle(string $payload): void
    {
    }
}
