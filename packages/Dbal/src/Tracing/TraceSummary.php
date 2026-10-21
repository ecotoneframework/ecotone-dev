<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Tracing;

use Ecotone\Api\Tracing\Span;
use Ecotone\Api\Tracing\SpanAttribute;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 *
 * @internal
 */
final class TraceSummary
{
    public function __construct(
        public readonly string $traceId,
        public readonly int $startedAtInMicroseconds,
        public readonly int $durationMs,
        public readonly string $status,
        public readonly string $rootEndpoint,
    ) {
    }

    /**
     * @param Span[] $traceSpans
     */
    public static function of(Span $entry, string $status, array $traceSpans): self
    {
        $startedAt = $entry->startedAt->unixTime()->inMicroseconds();
        $parentOf = [];
        foreach ($traceSpans as $span) {
            $parentOf[$span->spanId] = $span->parentSpanId;
        }
        $candidates = array_filter($traceSpans, fn (Span $span): bool => isset($span->attributes[SpanAttribute::ENDPOINT_ID]) && $span->startedAt->unixTime()->inMicroseconds() >= $startedAt);
        $order = fn (Span $span): array => [self::depthOf($span->spanId, $parentOf), $span->startedAt->unixTime()->inMicroseconds(), $span->spanId];
        usort($candidates, fn (Span $first, Span $second): int => $order($first) <=> $order($second));

        return new self(
            $entry->traceId,
            $startedAt,
            FlowEntries::durationInMilliseconds($entry->endedAt->unixTime()->inMicroseconds() - $startedAt),
            $status,
            $candidates === [] ? $entry->name : (string) $candidates[0]->attributes[SpanAttribute::ENDPOINT_ID],
        );
    }

    /**
     * @param array<string, string|null> $parentOf
     */
    private static function depthOf(string $spanId, array $parentOf): int
    {
        $depth = 0;
        while (isset($parentOf[$spanId])) {
            $spanId = $parentOf[$spanId];
            $depth++;
        }

        return $depth;
    }
}
