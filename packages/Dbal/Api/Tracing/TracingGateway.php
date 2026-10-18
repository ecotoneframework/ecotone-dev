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
    public function isCompletedFor(string $tracingId, ?string $destination = null, array $metadata = []): bool;

    public function isCompletedWithin(string $tracingId, Duration $timeout, ?string $destination = null, array $metadata = []): bool;

    public function fetchFlow(string $tracingId, array $metadata = []): ?Flow;
}
