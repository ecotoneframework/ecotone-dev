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
     * @param string[] $failedDestinations
     */
    public static function forTrace(string $traceId, Duration $timeout, array $pendingDestinations, array $failedDestinations): self
    {
        $failed = $failedDestinations === [] ? '' : sprintf(' Failed: %s, whose last attempt failed and whose message is in the error channel or awaiting a retry.', implode(', ', $failedDestinations));
        $pending = array_values(array_diff($pendingDestinations, $failedDestinations));
        $stillPending = $pending === [] ? '' : sprintf(' Still pending: %s.', implode(', ', $pending));
        $advice = $pending === [] ? ' Await with a longer timeout once the failure is resolved.' : ' Run the consumer of the pending channel, or await with a longer timeout.';

        return new self(sprintf('Trace %s did not complete within %d milliseconds.%s%s%s', $traceId, $timeout->inMilliseconds(), $failed, $stillPending, $advice));
    }

    public static function forUnrecordedTrace(string $traceId, Duration $timeout): self
    {
        return new self(sprintf(
            'No span was recorded under trace %s within %d milliseconds. Send the command with that traceparent in its metadata, and check that the process handling it stores traces.',
            $traceId,
            $timeout->inMilliseconds(),
        ));
    }

    public static function forDestination(string $traceId, string $destination, Duration $timeout, bool $failed): self
    {
        return new self(sprintf(
            'Trace %s did not complete for %s within %d milliseconds.%s',
            $traceId,
            $destination,
            $timeout->inMilliseconds(),
            $failed ? ' Its last attempt failed and its message is in the error channel or awaiting a retry.' : ' Run the consumer of the channel that handler is attached to, or await with a longer timeout.',
        ));
    }

    /**
     * @param string[] $reachedDestinations
     */
    public static function forUnreachedDestination(string $traceId, string $destination, Duration $timeout, array $reachedDestinations): self
    {
        return new self(sprintf(
            'Trace %s was neither sent to nor processed by %s within %d milliseconds. Check the endpointId or projection name; the trace reached: %s.',
            $traceId,
            $destination,
            $timeout->inMilliseconds(),
            $reachedDestinations === [] ? 'nothing yet' : implode(', ', $reachedDestinations),
        ));
    }
}
