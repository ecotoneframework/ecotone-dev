<?php

declare(strict_types=1);

namespace App\Snapshot;

use App\Settings\PulseSettings;
use Ecotone\Api\Dbal\Tracing\TracingGateway;
use Ecotone\Api\Scheduling\Duration;
use Psr\Clock\ClockInterface;

final readonly class ReplyAwaiter
{
    public function __construct(
        private TracingGateway $tracingGateway,
        private PulseSettings $settings,
        private ClockInterface $clock,
    ) {
    }

    public static function newTracingId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * @param list<RequestResult> $results
     */
    public function await(array $results): void
    {
        if ($this->settings->awaitTimeoutSeconds === 0) {
            return;
        }

        $deadline = $this->millisecondsNow() + $this->settings->awaitTimeoutSeconds * 1000;
        foreach ($results as $result) {
            if (! $result->sent) {
                continue;
            }
            $remainingMilliseconds = max(0, $deadline - $this->millisecondsNow());
            $this->tracingGateway->isCompletedWithin($result->tracingId, Duration::milliseconds($remainingMilliseconds), $result->destination);
        }
    }

    private function millisecondsNow(): int
    {
        return (int) $this->clock->now()->format('Uv');
    }
}
