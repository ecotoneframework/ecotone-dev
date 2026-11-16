<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\Scheduling\DatePoint;
use Ecotone\Api\Tracing\SpanContext;
use Ecotone\Tracing\ActiveSpan;

/**
 * licence Apache-2.0
 */
final class ExecutionStep
{
    /**
     * @param array<string, scalar> $attributes
     */
    public function __construct(
        public readonly SpanContext $context,
        public readonly ?string $parentSpanId,
        public readonly ?ActiveSpan $owner,
        public readonly string $name,
        public readonly array $attributes,
        public readonly DatePoint $startedAt,
    ) {
    }
}
