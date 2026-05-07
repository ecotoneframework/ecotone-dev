<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\Handler\ErrorChannelAsync;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\ErrorChannel;
use Ecotone\Modelling\Attribute\CommandHandler;
use RuntimeException;

/**
 * licence Enterprise
 *
 * Two async command handlers share the same async transport channel,
 * but each declares its own #[ErrorChannel] via #[Asynchronous] asynchronousExecution.
 * When a handler throws, the polling consumer routes the failure to that
 * handler's specific error channel — not to a single per-channel error channel.
 */
final class AsyncFailingHandler
{
    public const SHARED_ASYNC_CHANNEL = 'sharedAsync';
    public const ROUTING_KEY_A = 'async.handler.a';
    public const ROUTING_KEY_B = 'async.handler.b';
    public const ERROR_CHANNEL_A = 'errorChannelA';
    public const ERROR_CHANNEL_B = 'errorChannelB';

    #[Asynchronous(self::SHARED_ASYNC_CHANNEL, asynchronousExecution: [new ErrorChannel(self::ERROR_CHANNEL_A)])]
    #[CommandHandler(self::ROUTING_KEY_A, 'asyncHandlerA')]
    public function handleA(string $payload): void
    {
        throw new RuntimeException('handler-a-failure');
    }

    #[Asynchronous(self::SHARED_ASYNC_CHANNEL, asynchronousExecution: [new ErrorChannel(self::ERROR_CHANNEL_B)])]
    #[CommandHandler(self::ROUTING_KEY_B, 'asyncHandlerB')]
    public function handleB(string $payload): void
    {
        throw new RuntimeException('handler-b-failure');
    }
}
