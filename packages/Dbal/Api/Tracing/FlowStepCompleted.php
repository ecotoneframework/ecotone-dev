<?php

declare(strict_types=1);

namespace Ecotone\Api\Dbal\Tracing;

use Ecotone\Api\Tracing\Span;

/**
 * licence Enterprise
 *
 * Use requires an Ecotone Enterprise licence (see LICENSE-ENTERPRISE; trial: https://ecotone.tech/pricing).
 */
final class FlowStepCompleted
{
    public function __construct(public readonly Span $step)
    {
    }
}
