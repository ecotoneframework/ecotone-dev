<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Recoverability;

use Closure;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\Assert;
use Throwable;

/**
 * @internal
 */
final class RetryTemplate implements DefinedObject
{
    public const FIRST_RETRY = 1;
    /**
     * @var int in milliseconds
     */
    private int $initialDelay;
    private int $multiplier;
    private ?int $maxDelay;
    private ?int $maxAttempts;

    public function __construct(int $initialDelay, int $multiplier, ?int $maxDelay, ?int $maxAttempts)
    {
        $this->initialDelay = $initialDelay;
        $this->multiplier = $multiplier;
        $this->maxDelay = $maxDelay;
        $this->maxAttempts = $maxAttempts;
    }

    public static function createNeverRetry(): self
    {
        return new self(0, 0, null, 0);
    }

    /**
     * @return int delay in milliseconds
     */
    public function calculateNextDelay(int $retryNumber): int
    {
        Assert::isTrue($this->canBeCalledNextTime($retryNumber), "Retry template exceed number of possible tries {$retryNumber} of {$this->maxAttempts}. Should not be called anymore.");

        return $this->delayForRetryNumber($retryNumber);
    }

    public function runCallbackWithRetries(Closure $closure, Message $message, string $exceptionClass, LoggingGateway $logger, string $retryMessage): void
    {
        $retryNumber = 0;
        do {
            try {
                $closure();
                break;
            } catch (Throwable $exception) {
                if (! $exception instanceof $exceptionClass) {
                    throw $exception;
                }

                if (! $this->canBeCalledNextTime($retryNumber)) {
                    throw $exception;
                }

                $logger->info($retryMessage, $message, $exception);
                $retryNumber++;
                usleep($this->calculateNextDelay($retryNumber) * 1000);
            }
        } while (true);
    }

    public function canBeCalledNextTime(int $retryNumber): bool
    {
        if (! is_null($this->maxDelay) && $this->delayForRetryNumber($retryNumber) > $this->maxDelay) {
            return false;
        }
        if (is_null($this->maxAttempts)) {
            return true;
        }

        return $retryNumber <= $this->maxAttempts;
    }

    private function delayForRetryNumber(int $retryNumber): int
    {
        if ($retryNumber === 0) {
            return 0;
        }
        if ($retryNumber === self::FIRST_RETRY) {
            return $this->initialDelay;
        }

        return $this->delayForRetryNumber($retryNumber - 1) * $this->multiplier;
    }

    public function getMaxAttempts(): ?int
    {
        return $this->maxAttempts;
    }

    public function getDefinition(): Definition
    {
        return new Definition(self::class, [
            $this->initialDelay,
            $this->multiplier,
            $this->maxDelay,
            $this->maxAttempts,
        ]);
    }
}
