<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Recoverability;

use Ecotone\Api\Scheduling\Duration;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Support\Assert;

/**
 * @internal
 */
/**
 * licence Apache-2.0
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

    public function durationToNextRetry(int $retryNumber): Duration
    {
        return Duration::milliseconds($this->calculateNextDelay($retryNumber))->zeroIfNegative();
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

    public function describe(): string
    {
        $shown = 5;
        $delays = [];
        for ($retryNumber = self::FIRST_RETRY; $retryNumber <= $shown && $this->canBeCalledNextTime($retryNumber); $retryNumber++) {
            $delays[] = $this->delayForRetryNumber($retryNumber) . ' ms';
        }
        $retries = 0;
        while ($retries < 1000 && $this->canBeCalledNextTime($retries + 1)) {
            $retries++;
        }

        if ($delays === []) {
            return 'no retry';
        }
        $schedule = count($delays) === 1 ? $delays[0] : implode(', ', array_slice($delays, 0, -1)) . ' and ' . end($delays);
        if ($retries === 1000) {
            return 'retries without a limit: after ' . implode(', ', $delays) . ', each next delay ' . $this->multiplier . ' times longer';
        }

        return sprintf('%d %s: after %s%s', $retries, $retries === 1 ? 'retry' : 'retries', $schedule, $retries > $shown ? ', …' : '');
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
