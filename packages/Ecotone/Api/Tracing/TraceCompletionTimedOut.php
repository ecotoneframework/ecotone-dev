<?php

declare(strict_types=1);

namespace Ecotone\Api\Tracing;

use Ecotone\Api\Scheduling\Duration;

use function implode;

use RuntimeException;

/**
 * licence Enterprise
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 */
final class TraceCompletionTimedOut extends RuntimeException
{
    /**
     * @param string[] $pendingDestinations
     */
    public static function forTrace(string $traceId, Duration $timeout, array $pendingDestinations): self
    {
        return new self(sprintf(
            'Trace %s did not complete within %d milliseconds. Still pending: %s. Run the consumer of the channel those handlers are attached to, or await with a longer timeout.',
            $traceId,
            $timeout->inMilliseconds(),
            $pendingDestinations === [] ? 'the root of the trace, which was not recorded yet' : implode(', ', $pendingDestinations),
        ));
    }

    public static function forDestination(string $traceId, string $destination, Duration $timeout): self
    {
        return new self(sprintf(
            'Trace %s did not complete for %s within %d milliseconds. Run the consumer of the channel that handler is attached to, or await with a longer timeout.',
            $traceId,
            $destination,
            $timeout->inMilliseconds(),
        ));
    }
}
