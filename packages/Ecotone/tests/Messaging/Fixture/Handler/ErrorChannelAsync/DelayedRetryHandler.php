<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\Handler\ErrorChannelAsync;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\DelayedRetry;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use RuntimeException;

/**
 * licence Enterprise
 */
final class DelayedRetryHandler
{
    public const ASYNC_CHANNEL = 'delayedRetryAsync';
    public const ROUTING_KEY_RECOVERS = 'retry.recovers';
    public const ROUTING_KEY_DEAD_LETTER = 'retry.deadletter';
    public const ROUTING_KEY_OVERRIDE = 'retry.override';
    public const DEAD_LETTER_CHANNEL = 'retryDeadLetterChannel';

    public int $attemptsRecovers = 0;
    public int $attemptsDeadLetter = 0;
    public int $attemptsOverride = 0;
    public bool $finallyHandled = false;

    #[Asynchronous(self::ASYNC_CHANNEL, asynchronousExecution: [
        new DelayedRetry(initialDelayMs: 1, multiplier: 1, maxAttempts: 3),
    ])]
    #[CommandHandler(self::ROUTING_KEY_RECOVERS, 'retryRecovers')]
    public function recovers(string $payload): void
    {
        $this->attemptsRecovers++;
        if ($this->attemptsRecovers < 2) {
            throw new RuntimeException('transient');
        }
        $this->finallyHandled = true;
    }

    #[Asynchronous(self::ASYNC_CHANNEL, asynchronousExecution: [
        new DelayedRetry(
            initialDelayMs: 1,
            multiplier: 1,
            maxAttempts: 2,
            deadLetterChannel: self::DEAD_LETTER_CHANNEL,
        ),
    ])]
    #[CommandHandler(self::ROUTING_KEY_DEAD_LETTER, 'retryDeadLetter')]
    public function alwaysFails(string $payload): void
    {
        $this->attemptsDeadLetter++;
        throw new RuntimeException('permanent');
    }

    #[Asynchronous(self::ASYNC_CHANNEL, asynchronousExecution: [
        new DelayedRetry(
            initialDelayMs: 1,
            multiplier: 1,
            maxAttempts: 1,
            deadLetterChannel: self::DEAD_LETTER_CHANNEL,
        ),
    ])]
    #[CommandHandler(self::ROUTING_KEY_OVERRIDE, 'retryOverride')]
    public function alwaysFailsOverridingDefault(string $payload): void
    {
        $this->attemptsOverride++;
        throw new RuntimeException('permanent');
    }

    #[QueryHandler('retryHandler.attemptsRecovers')]
    public function getAttemptsRecovers(): int
    {
        return $this->attemptsRecovers;
    }

    #[QueryHandler('retryHandler.attemptsDeadLetter')]
    public function getAttemptsDeadLetter(): int
    {
        return $this->attemptsDeadLetter;
    }

    #[QueryHandler('retryHandler.attemptsOverride')]
    public function getAttemptsOverride(): int
    {
        return $this->attemptsOverride;
    }

    #[QueryHandler('retryHandler.finallyHandled')]
    public function isFinallyHandled(): bool
    {
        return $this->finallyHandled;
    }
}
