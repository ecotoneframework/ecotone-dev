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
            'Trace %s did not complete within %d milliseconds. Still pending: %s. Run the consumer of the pending channel, replay the message from the dead letter if its handler failed, or await with a longer timeout.',
            $traceId,
            $timeout->inMilliseconds(),
            implode(', ', $pendingDestinations),
        ));
    }

    public static function forUnrecordedTrace(string $traceId, Duration $timeout): self
    {
        return new self(sprintf(
            'No span was recorded under trace %s within %d milliseconds. Send the command with that traceparent in its metadata, and check that the process handling it stores traces.',
            $traceId,
            $timeout->inMilliseconds(),
        ));
    }

    public static function forDestination(string $traceId, string $destination, Duration $timeout): self
    {
        return new self(sprintf(
            'Trace %s did not complete for %s within %d milliseconds. Run the consumer of the channel that handler is attached to, replay the message from the dead letter if the handler failed, or await with a longer timeout.',
            $traceId,
            $destination,
            $timeout->inMilliseconds(),
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
