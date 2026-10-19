<?php

declare(strict_types=1);

namespace Ecotone\Test\Flow;

use function array_map;

use Ecotone\Api\Tracing\Span;
use Ecotone\Api\Tracing\SpanKind;
use Ecotone\Api\Tracing\SpanStatus;

use function implode;
use function str_repeat;
use function str_starts_with;
use function var_export;

/**
 * licence Apache-2.0
 */
final class ExpectedSpan
{
    private const FRAMEWORK_NOISE_PREFIX = 'process MessageHeadersPropagatorInterceptor::';

    /**
     * @param array<string, scalar> $attributes
     * @param list<ExpectedSpan> $children
     */
    private function __construct(
        public readonly string $name,
        public readonly ?SpanKind $kind = null,
        public readonly ?SpanStatus $status = null,
        public readonly array $attributes = [],
        public readonly array $children = [],
        public readonly bool $root = false,
    ) {
    }

    public static function named(string $name): self
    {
        return new self($name);
    }

    public function asRoot(): self
    {
        return new self(...[...get_object_vars($this), 'root' => true]);
    }

    public function ofKind(SpanKind $kind): self
    {
        return new self(...[...get_object_vars($this), 'kind' => $kind]);
    }

    public function withStatus(SpanStatus $status): self
    {
        return new self(...[...get_object_vars($this), 'status' => $status]);
    }

    /**
     * @param array<string, scalar> $attributes
     */
    public function withAttributes(array $attributes): self
    {
        return new self(...[...get_object_vars($this), 'attributes' => [...$this->attributes, ...$attributes]]);
    }

    public function containing(ExpectedSpan ...$children): self
    {
        return new self(...[...get_object_vars($this), 'children' => [...$this->children, ...$children]]);
    }

    public function hasFailure(): bool
    {
        if ($this->status === SpanStatus::Error) {
            return true;
        }
        foreach ($this->children as $child) {
            if ($child->hasFailure()) {
                return true;
            }
        }

        return false;
    }

    public function withoutFailedSpans(): ?self
    {
        if ($this->status === SpanStatus::Error) {
            return null;
        }

        return new self(...[...get_object_vars($this), 'children' => array_values(array_filter(array_map(fn (ExpectedSpan $child) => $child->withoutFailedSpans(), $this->children)))]);
    }

    public function count(): int
    {
        return 1 + array_sum(array_map(fn (ExpectedSpan $child) => $child->count(), $this->children));
    }

    /**
     * @param list<ExpectedSpan> $expected
     * @param Span[] $spans
     */
    public static function mismatchIn(array $expected, array $spans): ?string
    {
        $tree = SpanTree::of($spans);
        foreach ($expected as $expectedSpan) {
            if (! $expectedSpan->matchesAnyOf($tree->all(), $tree)) {
                return "No recorded span tree matches:\n" . $expectedSpan->render(1) . "\nRecorded:\n" . $tree->render();
            }
        }

        return null;
    }

    /**
     * @param Span[] $spans
     */
    public static function namedIn(string $name, array $spans): bool
    {
        foreach ($spans as $span) {
            if ($span->name === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param Span[] $candidates
     */
    private function matchesAnyOf(array $candidates, SpanTree $tree): bool
    {
        foreach ($candidates as $candidate) {
            if ($this->matches($candidate, $tree)) {
                return true;
            }
        }

        return false;
    }

    private function matches(Span $span, SpanTree $tree): bool
    {
        if ($span->name !== $this->name
            || ($this->root && $span->parentSpanId !== null)
            || ($this->kind !== null && $span->kind !== $this->kind)
            || ($this->status !== null && $span->status !== $this->status)) {
            return false;
        }
        foreach ($this->attributes as $key => $value) {
            if (($span->attributes[$key] ?? null) !== $value) {
                return false;
            }
        }

        return self::childrenMatch($this->children, $tree->childrenOf($span), $tree);
    }

    /**
     * @param list<ExpectedSpan> $expectedChildren
     * @param Span[] $available
     */
    private static function childrenMatch(array $expectedChildren, array $available, SpanTree $tree): bool
    {
        if ($expectedChildren === []) {
            return true;
        }
        $expected = array_shift($expectedChildren);
        foreach ($available as $index => $candidate) {
            if ($expected->matches($candidate, $tree)) {
                $remaining = $available;
                unset($remaining[$index]);
                if (self::childrenMatch($expectedChildren, $remaining, $tree)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function render(int $depth): string
    {
        $line = str_repeat('  ', $depth) . $this->name
            . ($this->root ? ' <root>' : '')
            . ($this->kind === null ? '' : ' [' . $this->kind->name . ']')
            . ($this->status === null ? '' : ' (' . $this->status->name . ')')
            . ($this->attributes === [] ? '' : ' ' . implode(', ', array_map(fn (string $key, mixed $value) => $key . '=' . var_export($value, true), array_keys($this->attributes), $this->attributes)));

        return implode("\n", [$line, ...array_map(fn (ExpectedSpan $child) => $child->render($depth + 1), $this->children)]);
    }

    public static function isFrameworkNoise(Span $span): bool
    {
        return str_starts_with($span->name, self::FRAMEWORK_NOISE_PREFIX);
    }
}
