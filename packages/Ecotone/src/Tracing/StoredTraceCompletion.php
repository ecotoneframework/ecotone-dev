<?php

declare(strict_types=1);

namespace Ecotone\Tracing;

use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Scheduling\Duration;
use Ecotone\Api\Tracing\TraceCompletionTimedOut;

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

    public function __construct(
        private TraceStore $traceStore,
        private EcotoneClockInterface $clock,
    ) {
    }

    public function isCompleted(string $traceId): bool
    {
        return $this->traceStore->progressOf($traceId)->isCompleted();
    }

    public function isCompletedFor(string $traceId, #[Header(self::DESTINATION_HEADER)] string $destination): bool
    {
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
                throw TraceCompletionTimedOut::forTrace($traceId, $timeout, $progress->pendingDestinations());
            }
            $this->clock->sleep(Duration::milliseconds(self::POLL_INTERVAL_IN_MILLISECONDS));
        }
    }

    public function awaitFor(string $traceId, #[Header(self::DESTINATION_HEADER)] string $destination, #[Header(self::TIMEOUT_HEADER)] Duration $timeout): void
    {
        $deadline = $this->clock->now()->add($timeout);
        while (true) {
            if ($this->traceStore->progressOf($traceId)->isCompletedFor($destination)) {
                return;
            }
            if ($this->clock->now() >= $deadline) {
                throw TraceCompletionTimedOut::forDestination($traceId, $destination, $timeout);
            }
            $this->clock->sleep(Duration::milliseconds(self::POLL_INTERVAL_IN_MILLISECONDS));
        }
    }
}
