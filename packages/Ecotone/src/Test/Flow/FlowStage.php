<?php

declare(strict_types=1);

namespace Ecotone\Test\Flow;

use Closure;

/**
 * licence Apache-2.0
 */
final class FlowStage
{
    /**
     * @param Closure(\Ecotone\Api\Lite\Test\FlowTestSupport, array<string, mixed>): void $action
     * @param list<ExpectedSpan> $expectedSpans
     * @param list<string> $absentSpanNames
     */
    private function __construct(
        public readonly Closure $action,
        public readonly array $expectedSpans = [],
        public readonly array $absentSpanNames = [],
    ) {
    }

    /**
     * @param Closure(\Ecotone\Api\Lite\Test\FlowTestSupport, array<string, mixed>): void $action
     */
    public static function when(Closure $action): self
    {
        return new self($action);
    }

    public function expect(ExpectedSpan ...$spans): self
    {
        return new self(...[...get_object_vars($this), 'expectedSpans' => [...$this->expectedSpans, ...$spans]]);
    }

    public function expectNoSpanNamed(string ...$names): self
    {
        return new self(...[...get_object_vars($this), 'absentSpanNames' => [...$this->absentSpanNames, ...$names]]);
    }
}
