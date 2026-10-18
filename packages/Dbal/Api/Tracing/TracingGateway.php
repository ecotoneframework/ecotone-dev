<?php

declare(strict_types=1);

namespace Ecotone\Api\Dbal\Tracing;

use Ecotone\Api\Scheduling\Duration;

/**
 * licence Enterprise
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 */
interface TracingGateway
{
    public function isCompleted(string $tracingId): bool;

    public function isCompletedFor(string $tracingId, string $destination): bool;

    public function await(string $tracingId, Duration $timeout): void;

    public function awaitFor(string $tracingId, string $destination, Duration $timeout): void;
}
