<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Tracing;

use Ecotone\Api\Tracing\Span;
use Ecotone\Api\Tracing\SpanAttribute;
use Ecotone\Api\Tracing\SpanKind;
use Ecotone\Api\Tracing\SpanStatus;

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
final class FlowEntries
{
    public const STATUS_OK = 'ok';
    public const STATUS_ERROR = 'error';
    public const FLOW_TYPE_MAX_LENGTH = 255;

    /**
     * @param Span[] $spans
     * @param array<string, array<string, array{parentSpanId: ?string, failed: bool, entryStatus: ?string}>> $storedTraces
     */
    public static function plan(array $spans, array $storedTraces, ?string $onlyPossibleEntrySpanId): FlowEntryPlan
    {
        $spansByTrace = [];
        foreach ($spans as $span) {
            $spansByTrace[$span->traceId][] = $span;
        }

        $entries = [];
        $entriesToMarkFailed = [];
        foreach ($spansByTrace as $traceId => $batch) {
            $stored = $storedTraces[$traceId] ?? [];
            $parentOf = array_map(fn (array $storedSpan): ?string => $storedSpan['parentSpanId'], $stored);
            $failed = array_filter(array_map(fn (array $storedSpan): bool => $storedSpan['failed'], $stored));
            foreach ($batch as $span) {
                $parentOf[$span->spanId] ??= $span->parentSpanId;
                if ($span->status === SpanStatus::Error) {
                    $failed[$span->spanId] = true;
                }
            }

            $failedEntries = [];
            foreach (array_keys($failed) as $failedSpanId) {
                $failedEntries[self::topmostRecordedAncestorOf((string) $failedSpanId, $parentOf)] = true;
            }

            foreach ($batch as $span) {
                if (isset($stored[$span->spanId]) || isset($entries[$span->spanId]) || ! self::isEntry($span, $parentOf, $onlyPossibleEntrySpanId)) {
                    continue;
                }
                $entries[$span->spanId] = [
                    'flowType' => self::flowTypeOf($span, $batch),
                    'status' => isset($failedEntries[$span->spanId]) ? self::STATUS_ERROR : self::STATUS_OK,
                ];
            }
            foreach ($stored as $spanId => $storedSpan) {
                if ($storedSpan['entryStatus'] === self::STATUS_OK && isset($failedEntries[$spanId])) {
                    $entriesToMarkFailed[(string) $traceId][] = (string) $spanId;
                }
            }
        }

        return new FlowEntryPlan($entries, $entriesToMarkFailed);
    }

    public static function durationInMilliseconds(int $durationInMicroseconds): int
    {
        return (int) round($durationInMicroseconds / 1000);
    }

    /**
     * @param array<string, ?string> $parentOf
     */
    private static function isEntry(Span $span, array $parentOf, ?string $onlyPossibleEntrySpanId): bool
    {
        if ($onlyPossibleEntrySpanId !== null && $span->spanId !== $onlyPossibleEntrySpanId) {
            return false;
        }

        return $span->parentSpanId === null || ! array_key_exists($span->parentSpanId, $parentOf);
    }

    /**
     * @param array<string, ?string> $parentOf
     */
    private static function topmostRecordedAncestorOf(string $spanId, array $parentOf): string
    {
        $visited = [$spanId => true];
        while (($parentSpanId = $parentOf[$spanId] ?? null) !== null && array_key_exists($parentSpanId, $parentOf) && ! isset($visited[$parentSpanId])) {
            $spanId = $parentSpanId;
            $visited[$spanId] = true;
        }

        return $spanId;
    }

    /**
     * @param Span[] $batch
     */
    private static function flowTypeOf(Span $entry, array $batch): string
    {
        $destination = self::destinationOf($entry);
        if ($destination === null || $entry->kind === SpanKind::Consumer) {
            $destination = self::destinationOfFirstSendBy($entry, $batch) ?? $destination;
        }

        return mb_strcut($destination ?? self::withoutVerb($entry->name), 0, self::FLOW_TYPE_MAX_LENGTH, 'UTF-8');
    }

    /**
     * @param Span[] $batch
     */
    private static function destinationOfFirstSendBy(Span $entry, array $batch): ?string
    {
        $sends = array_filter($batch, fn (Span $span): bool => $span->parentSpanId === $entry->spanId
            && ($span->attributes[SpanAttribute::MESSAGING_OPERATION_TYPE] ?? null) === SpanAttribute::OPERATION_SEND
            && self::destinationOf($span) !== null);
        $order = fn (Span $span): array => [$span->kind === SpanKind::Producer ? 1 : 0, $span->startedAt->unixTime()->inMicroseconds(), (string) self::destinationOf($span)];
        usort($sends, fn (Span $first, Span $second): int => $order($first) <=> $order($second));

        return $sends === [] ? null : self::destinationOf($sends[0]);
    }

    private static function destinationOf(Span $span): ?string
    {
        $destination = $span->attributes[SpanAttribute::MESSAGING_DESTINATION_NAME] ?? null;

        return $destination === null || $destination === '' ? null : (string) $destination;
    }

    private static function withoutVerb(string $spanName): string
    {
        $parts = explode(' ', $spanName, 2);

        return $parts[1] ?? $parts[0];
    }
}
