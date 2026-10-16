<?php

declare(strict_types=1);

namespace Ecotone\Api\Tracing;

use Ecotone\Api\Scheduling\Duration;

/**
 * licence Enterprise
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 */
interface TraceCompletion
{
    public function isCompleted(string $traceId): bool;

    public function isCompletedFor(string $traceId, string $destination): bool;

    public function await(string $traceId, Duration $timeout): void;

    public function awaitFor(string $traceId, string $destination, Duration $timeout): void;
}
