<?php

declare(strict_types=1);

namespace Ecotone\Api\Dbal\Tracing;

use Ecotone\Api\Tracing\Span;

/**
 * licence Enterprise
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 */
final class SpanEnded
{
    public function __construct(public readonly Span $span)
    {
    }
}
