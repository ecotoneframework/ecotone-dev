<?php

declare(strict_types=1);

namespace Ecotone\Tracing;

use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Scheduling\Duration;
use Ecotone\Api\Tracing\TraceCompletionTimedOut;
use Ecotone\Messaging\Config\ConfigurationException;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
final class StoredTraceCompletion
{
    public const DESTINATION_HEADER = 'ecotone_tracing_completion_destination';
    public const TIMEOUT_HEADER = 'ecotone_tracing_completion_timeout';
    private const POLL_INTERVAL_IN_MILLISECONDS = 50;

    /**
     * @param string[] $pollingProjectionNames
     */
    public function __construct(
        private TraceStore $traceStore,
        private EcotoneClockInterface $clock,
        private array $pollingProjectionNames,
    ) {
    }

    public function isCompleted(string $traceId): bool
    {
        return $this->traceStore->progressOf($traceId)->isCompleted();
    }

    public function isCompletedFor(string $traceId, #[Header(self::DESTINATION_HEADER)] string $destination): bool
    {
        $this->refusePollingProjection($destination);

        return $this->traceStore->progressOf($traceId)->isCompletedFor($destination);
    }

    public function await(string $traceId, #[Header(self::TIMEOUT_HEADER)] Duration $timeout): void
    {
        $deadline = $this->clock->now()->add($timeout);
        while (true) {
            $progress = $this->traceStore->progressOf($traceId);
            if ($progress->isCompleted()) {
                return;
            }
            if ($this->clock->now() >= $deadline) {
                throw $progress->recorded
                    ? TraceCompletionTimedOut::forTrace($traceId, $timeout, $progress->pendingDestinations(), $progress->failedDestinations())
                    : TraceCompletionTimedOut::forUnrecordedTrace($traceId, $timeout);
            }
            $this->clock->sleep(Duration::milliseconds(self::POLL_INTERVAL_IN_MILLISECONDS));
        }
    }

    public function awaitFor(string $traceId, #[Header(self::DESTINATION_HEADER)] string $destination, #[Header(self::TIMEOUT_HEADER)] Duration $timeout): void
    {
        $this->refusePollingProjection($destination);
        $deadline = $this->clock->now()->add($timeout);
        while (true) {
            $progress = $this->traceStore->progressOf($traceId);
            if ($progress->isCompletedFor($destination)) {
                return;
            }
            if ($this->clock->now() >= $deadline) {
                throw match (true) {
                    ! $progress->recorded => TraceCompletionTimedOut::forUnrecordedTrace($traceId, $timeout),
                    ! $progress->reached($destination) => TraceCompletionTimedOut::forUnreachedDestination($traceId, $destination, $timeout, $progress->destinations()),
                    default => TraceCompletionTimedOut::forDestination($traceId, $destination, $timeout, in_array($destination, $progress->failedDestinations(), true)),
                };
            }
            $this->clock->sleep(Duration::milliseconds(self::POLL_INTERVAL_IN_MILLISECONDS));
        }
    }

    private function refusePollingProjection(string $destination): void
    {
        if (in_array($destination, $this->pollingProjectionNames, true)) {
            throw ConfigurationException::create(sprintf('Projection %s runs by #[Polling], so it is not part of the trace that appended its events and TraceCompletion cannot await it. Await the handler that published the event, or make the projection event-driven by removing #[Polling].', $destination));
        }
    }
}
