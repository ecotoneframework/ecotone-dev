<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Tracing;

use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Dbal\Tracing\FlowTrackingTimedOut;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Scheduling\Duration;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Tracing\TracingId;

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
final class StoredTracingGateway
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

    public function isCompleted(string $tracingId): bool
    {
        $tracingId = TracingId::normalize($tracingId);
        return $this->traceStore->progressOf($tracingId)->isCompleted();
    }

    public function isCompletedFor(string $tracingId, #[Header(self::DESTINATION_HEADER)] string $destination): bool
    {
        $tracingId = TracingId::normalize($tracingId);
        $this->refusePollingProjection($destination);

        return $this->traceStore->progressOf($tracingId)->isCompletedFor($destination);
    }

    public function await(string $tracingId, #[Header(self::TIMEOUT_HEADER)] Duration $timeout): void
    {
        $tracingId = TracingId::normalize($tracingId);
        $deadline = $this->clock->now()->add($timeout);
        while (true) {
            $progress = $this->traceStore->progressOf($tracingId);
            if ($progress->isCompleted()) {
                return;
            }
            if ($this->clock->now() >= $deadline) {
                throw $progress->recorded
                    ? FlowTrackingTimedOut::forTrace($tracingId, $timeout, $progress->pendingDestinations())
                    : FlowTrackingTimedOut::forUnrecordedTrace($tracingId, $timeout);
            }
            $this->clock->sleep(Duration::milliseconds(self::POLL_INTERVAL_IN_MILLISECONDS));
        }
    }

    public function awaitFor(string $tracingId, #[Header(self::DESTINATION_HEADER)] string $destination, #[Header(self::TIMEOUT_HEADER)] Duration $timeout): void
    {
        $tracingId = TracingId::normalize($tracingId);
        $this->refusePollingProjection($destination);
        $deadline = $this->clock->now()->add($timeout);
        while (true) {
            $progress = $this->traceStore->progressOf($tracingId);
            if ($progress->isCompletedFor($destination)) {
                return;
            }
            if ($this->clock->now() >= $deadline) {
                throw match (true) {
                    ! $progress->recorded => FlowTrackingTimedOut::forUnrecordedTrace($tracingId, $timeout),
                    ! $progress->reached($destination) => FlowTrackingTimedOut::forUnreachedDestination($tracingId, $destination, $timeout, $progress->destinations()),
                    default => FlowTrackingTimedOut::forDestination($tracingId, $destination, $timeout),
                };
            }
            $this->clock->sleep(Duration::milliseconds(self::POLL_INTERVAL_IN_MILLISECONDS));
        }
    }

    private function refusePollingProjection(string $destination): void
    {
        if (in_array($destination, $this->pollingProjectionNames, true)) {
            throw ConfigurationException::create(sprintf('Projection %s runs by #[Polling], so it is not part of the trace that appended its events and TracingGateway cannot await it. Await the handler that published the event, or make the projection event-driven by removing #[Polling].', $destination));
        }
    }
}
