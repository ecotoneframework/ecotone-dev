<?php

declare(strict_types=1);

namespace Ecotone\Test\Flow\Snapshot;

use Ecotone\Api\Tracing\Span;
use Ecotone\Api\Tracing\SpanAttribute;
use Ecotone\Test\Flow\SpanTree;

/**
 * licence Apache-2.0
 */
final class FlowTrace
{
    /**
     * @param Span[] $spans
     * @return list<array{depth: int, name: string, destination?: string, kind: string, status: string}>
     */
    public static function stepsOf(array $spans): array
    {
        $tree = SpanTree::of(array_filter($spans, fn (Span $span): bool => ! ($span->attributes[SpanAttribute::EXECUTION_STEP] ?? false)));
        $steps = [];
        foreach ($tree->roots() as $root) {
            $steps = [...$steps, ...self::stepsFrom($root, 0, $tree)];
        }

        return $steps;
    }

    /**
     * @return list<array{depth: int, name: string, destination?: string, kind: string, status: string}>
     */
    private static function stepsFrom(Span $span, int $depth, SpanTree $tree): array
    {
        $destination = $span->attributes[SpanAttribute::MESSAGING_DESTINATION_NAME] ?? null;
        $steps = [['depth' => $depth, 'name' => $span->name] + (is_string($destination) && ! str_ends_with($span->name, ' ' . $destination) ? ['destination' => $destination] : []) + ['kind' => $span->kind->name, 'status' => $span->status->name]];
        foreach ($tree->childrenOf($span) as $child) {
            $steps = [...$steps, ...self::stepsFrom($child, $depth + 1, $tree)];
        }

        return $steps;
    }
}
