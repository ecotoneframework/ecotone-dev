<?php

declare(strict_types=1);

namespace Ecotone\Test\Flow;

use Ecotone\Api\Tracing\Span;

/**
 * licence Apache-2.0
 */
final class FlowScenario
{
    /**
     * @param list<class-string> $classesToResolve
     * @param array<string|int, object> $services
     * @param list<object> $extensionObjects
     * @param list<FlowStage> $stages
     */
    public function __construct(
        public readonly string $name,
        public readonly array $classesToResolve,
        public readonly array $services,
        public readonly array $extensionObjects,
        public readonly array $stages,
    ) {
    }

    public function endsInFailure(): bool
    {
        foreach ($this->stages as $stage) {
            foreach ($stage->expectedSpans as $expectedSpan) {
                if ($expectedSpan->hasFailure()) {
                    return true;
                }
            }
        }

        return false;
    }

    public function withoutFailedSpans(): self
    {
        return new self($this->name, $this->classesToResolve, $this->services, $this->extensionObjects, array_map(fn (FlowStage $stage) => $stage->withoutFailedSpans(), $this->stages));
    }

    /**
     * @param Span[] $spans
     */
    public function mismatchAfterStage(int $stageIndex, array $spans): ?string
    {
        $stage = $this->stages[$stageIndex];
        $mismatch = ExpectedSpan::mismatchIn($stage->expectedSpans, $spans);
        if ($mismatch !== null) {
            return $mismatch;
        }
        $tree = SpanTree::of($spans);
        foreach ($stage->absentSpanNames as $absentSpanName) {
            if (ExpectedSpan::namedIn($absentSpanName, $tree->all())) {
                return "Span {$absentSpanName} was recorded, though the stage expects it not to be. Recorded:\n" . $tree->render();
            }
        }
        $expectedCount = $this->expectedSpanCountUpTo($stageIndex);
        if ($stage->noOtherSpans && count($tree->all()) !== $expectedCount) {
            return 'Expected exactly ' . $expectedCount . ' spans, recorded ' . count($tree->all()) . ":\n" . $tree->render();
        }

        return null;
    }

    private function expectedSpanCountUpTo(int $stageIndex): int
    {
        $count = 0;
        foreach (array_slice($this->stages, 0, $stageIndex + 1) as $stage) {
            foreach ($stage->expectedSpans as $expectedSpan) {
                $count += $expectedSpan->count();
            }
        }

        return $count;
    }
}
