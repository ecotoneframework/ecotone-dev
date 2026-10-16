<?php

declare(strict_types=1);

namespace Ecotone\Api\Tracing;

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
