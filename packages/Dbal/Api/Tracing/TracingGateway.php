<?php

declare(strict_types=1);

namespace Ecotone\Api\Dbal\Tracing;

use Ecotone\Api\Attribute\PropagateHeaders;
use Ecotone\Api\Scheduling\Duration;

/**
 * licence Enterprise
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 */
#[PropagateHeaders]
interface TracingGateway
{
    public function isCompleted(string $tracingId, array $metadata = []): bool;

    public function isCompletedFor(string $tracingId, string $destination, array $metadata = []): bool;

    public function await(string $tracingId, Duration $timeout, array $metadata = []): void;

    public function awaitFor(string $tracingId, string $destination, Duration $timeout, array $metadata = []): void;
}
