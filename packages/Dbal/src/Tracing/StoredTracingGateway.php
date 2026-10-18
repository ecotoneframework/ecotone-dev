<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Tracing;

use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\Headers;
use Ecotone\Api\Dbal\Tracing\Flow;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Scheduling\Duration;
use Ecotone\Api\Tracing\Span;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Support\InvalidArgumentException;
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
    private const LONGEST_AWAIT_IN_MILLISECONDS = 15 * 60 * 1000;

    /**
     * @param string[] $pollingProjectionNames
     * @param array<string, string[]> $destinationsByClass
     */
    public function __construct(
        private TraceStore $traceStore,
        private EcotoneClockInterface $clock,
        private array $pollingProjectionNames,
        private array $destinationsByClass,
        private ?string $tenantHeaderName,
        private ?string $exampleTenant,
    ) {
    }

    public function isCompletedFor(string $tracingId, #[Header(self::DESTINATION_HEADER)] ?string $destination, #[Headers] array $headers): bool
    {
        $this->refuseMissingTenant('isCompletedFor($tracingId', $headers);
        $tracingId = TracingId::normalize($tracingId);

        return $this->isCompletedNow($tracingId, $destination === null ? null : $this->destinationsOf($destination));
    }

    public function isCompletedWithin(string $tracingId, #[Header(self::TIMEOUT_HEADER)] Duration $timeout, #[Header(self::DESTINATION_HEADER)] ?string $destination, #[Headers] array $headers): bool
    {
        $this->refuseMissingTenant('isCompletedWithin($tracingId, $timeout', $headers);
        $this->refuseWaitingLongerThanFifteenMinutes($timeout);
        $tracingId = TracingId::normalize($tracingId);
        $destinations = $destination === null ? null : $this->destinationsOf($destination);
        $deadline = $this->clock->now()->add($timeout);
        while (! $this->isCompletedNow($tracingId, $destinations)) {
            if ($this->clock->now() >= $deadline) {
                return false;
            }
            $this->clock->sleep(Duration::milliseconds(self::POLL_INTERVAL_IN_MILLISECONDS));
        }

        return true;
    }

    public function fetchFlow(string $tracingId, #[Headers] array $headers): ?Flow
    {
        $this->refuseMissingTenant('fetchFlow($tracingId', $headers);
        $tracingId = TracingId::normalize($tracingId);
        $steps = $this->traceStore->stepsOf($tracingId);
        if ($steps === []) {
            return null;
        }
        usort($steps, fn (Span $first, Span $second): int => $first->startedAt <=> $second->startedAt);
        $progress = TraceLedger::progressOf($tracingId, $steps);

        return new Flow($tracingId, $progress->isCompleted(), $progress->pendingDestinations(), $progress->destinations(), $steps);
    }

    /**
     * @param string[]|null $destinations
     */
    private function isCompletedNow(string $tracingId, ?array $destinations): bool
    {
        $progress = $this->traceStore->progressOf($tracingId);

        return $destinations === null ? $progress->isCompleted() : $progress->isCompletedFor(...$destinations);
    }

    private function refuseWaitingLongerThanFifteenMinutes(Duration $timeout): void
    {
        if ($timeout->inMilliseconds() <= self::LONGEST_AWAIT_IN_MILLISECONDS) {
            return;
        }

        throw InvalidArgumentException::create(sprintf(
            'TracingGateway::isCompletedWithin() was asked to wait %d milliseconds, but it blocks the calling process for as long as it waits, which past 15 minutes ties that process up for an extensive period. Wait at most 15 minutes, or react to the flow event-driven instead: a method with #[OnFlowCompleted] receives a FlowCompleted once the flow completes, and one with #[OnFlowStepCompleted] a FlowStepCompleted for each of its steps.',
            $timeout->inMilliseconds(),
        ));
    }

    /**
     * @param array<string, mixed> $headers
     */
    private function refuseMissingTenant(string $call, array $headers): void
    {
        if ($this->tenantHeaderName === null || isset($headers[$this->tenantHeaderName])) {
            return;
        }

        throw InvalidArgumentException::create(sprintf(
            "TracingGateway reads a flow from the database of the tenant it ran for, and this call names no tenant. Pass the tenant as metadata, for example %s, metadata: ['%s' => '%s']).",
            $call,
            $this->tenantHeaderName,
            $this->exampleTenant,
        ));
    }

    /**
     * @return string[]
     */
    private function destinationsOf(string $destination): array
    {
        $destinations = $this->destinationsByClass[$destination] ?? null;
        if ($destinations === null && (class_exists($destination) || interface_exists($destination))) {
            throw ConfigurationException::create(sprintf('%s has no handler or projection Ecotone registered, so no flow can reach it. Pass a class with a #[CommandHandler], #[EventHandler], #[QueryHandler] or #[InternalHandler] method, a #[Projection] class, an endpointId or a projection name.', $destination));
        }
        $destinations ??= [$destination];
        foreach ($destinations as $resolvedDestination) {
            $this->refusePollingProjection($resolvedDestination);
        }

        return $destinations;
    }

    private function refusePollingProjection(string $destination): void
    {
        if (in_array($destination, $this->pollingProjectionNames, true)) {
            throw ConfigurationException::create(sprintf('Projection %s runs by #[Polling], so it is not part of the trace that appended its events and TracingGateway cannot wait for it. Await the handler that published the event, or make the projection event-driven by removing #[Polling].', $destination));
        }
    }
}
