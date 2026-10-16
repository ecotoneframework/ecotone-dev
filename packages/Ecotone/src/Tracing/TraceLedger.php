<?php

declare(strict_types=1);

namespace Ecotone\Tracing;

use Ecotone\Api\Tracing\Span;
use Ecotone\Api\Tracing\SpanAttribute;
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
 */
final class TraceLedger
{
    /**
     * @param Span[] $spans
     */
    public static function progressOf(string $traceId, array $spans): TraceProgress
    {
        if ($spans === []) {
            return TraceProgress::unknown($traceId);
        }

        $recordedSpanIds = [];
        foreach ($spans as $span) {
            $recordedSpanIds[$span->spanId] = true;
        }

        $rootEnded = false;
        $sent = [];
        $processed = [];
        $failed = [];
        foreach ($spans as $span) {
            if ($span->parentSpanId === null || ! isset($recordedSpanIds[$span->parentSpanId])) {
                $rootEnded = true;
            }
            $destination = self::destinationOf($span);
            if ($destination === null) {
                continue;
            }
            $operation = $span->attributes[SpanAttribute::MESSAGING_OPERATION_TYPE] ?? null;
            if ($operation === SpanAttribute::OPERATION_SEND) {
                $sent[$destination] = ($sent[$destination] ?? 0) + 1;
            } elseif ($operation === SpanAttribute::OPERATION_PROCESS && $span->status === SpanStatus::Error) {
                $failed[$destination] = ($failed[$destination] ?? 0) + 1;
            } elseif ($operation === SpanAttribute::OPERATION_PROCESS) {
                $processed[$destination] = ($processed[$destination] ?? 0) + 1;
            }
        }

        return new TraceProgress($traceId, true, $rootEnded, $sent, $processed, $failed);
    }

    public static function destinationOf(Span $span): ?string
    {
        $destination = $span->attributes[SpanAttribute::ENDPOINT_ID] ?? $span->attributes[SpanAttribute::PROJECTION_NAME] ?? null;

        return $destination === null ? null : (string) $destination;
    }
}
