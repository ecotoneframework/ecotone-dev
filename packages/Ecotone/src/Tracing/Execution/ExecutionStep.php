<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\Scheduling\DatePoint;
use Ecotone\Api\Tracing\SpanContext;
use Ecotone\Tracing\ActiveSpan;
use Throwable;

/**
 * licence Apache-2.0
 */
final class ExecutionStep
{
    public ?ActiveSpan $span = null;
    /** @var ?array{outcome: string, hints: list<ExecutionHint>, failure: ?Throwable} */
    public ?array $ending = null;

    /**
     * @param array<string, scalar> $attributes
     */
    public function __construct(
        public readonly string $name,
        public readonly array $attributes,
        public readonly ?ActiveSpan $owner,
        public readonly bool $entry,
        public readonly ?ExecutionStep $parentStep,
        public readonly ?SpanContext $parentContext,
        public readonly DatePoint $startedAt,
        public readonly int $lastCoroutineStartedBefore,
    ) {
    }
}
