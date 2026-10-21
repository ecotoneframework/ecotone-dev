<?php

declare(strict_types=1);

namespace App\Trace;

final readonly class TraceView
{
    /**
     * @param list<array{service: string, state: string, status: string, failure: ?string, truncated: bool, totalSpans: int}> $states
     */
    public function __construct(public string $traceId, public CrossServiceTrace $trace, public array $states, public bool $waiting)
    {
    }
}
