<?php

declare(strict_types=1);

namespace Ecotone\Api\Dbal\Tracing;

/**
 * licence Enterprise
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 */
final class FlowCompleted
{
    public function __construct(public readonly string $tracingId)
    {
    }
}
