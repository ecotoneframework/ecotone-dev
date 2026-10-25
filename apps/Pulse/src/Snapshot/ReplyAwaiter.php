<?php

declare(strict_types=1);

namespace App\Snapshot;

use App\Settings\PulseSettings;
use Ecotone\Api\Dbal\Tracing\TracingGateway;
use Ecotone\Api\Scheduling\Duration;

final readonly class ReplyAwaiter
{
    public function __construct(
        private TracingGateway $tracingGateway,
        private PulseSettings $settings,
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

        $deadline = hrtime(true) + $this->settings->awaitTimeoutSeconds * 1_000_000_000;
        foreach ($results as $result) {
            if (! $result->sent) {
                continue;
            }
            $remainingMilliseconds = max(0, intdiv($deadline - hrtime(true), 1_000_000));
            $this->tracingGateway->isCompletedWithin($result->tracingId, Duration::milliseconds($remainingMilliseconds), $result->destination);
        }
    }
}
