<?php

declare(strict_types=1);

namespace Ecotone\Api\Tracing;

/**
 * licence Enterprise
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 */
final class TraceCompleted
{
    public function __construct(public readonly string $tracingId)
    {
    }
}
