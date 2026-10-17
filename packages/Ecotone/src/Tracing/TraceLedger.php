<?php

declare(strict_types=1);

namespace Ecotone\Tracing;

use Ecotone\Api\Messaging\MessageHeaders;
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

        $rootEnded = false;
        $sent = [];
        $processed = [];
        $failed = [];
        $sentDeliveries = [];
        $processedDeliveries = [];
        foreach ($spans as $span) {
            if ($span->attributes[SpanAttribute::TRACE_ROOT] ?? false) {
                $rootEnded = true;
            }
            $destination = self::destinationOf($span);
            if ($destination === null) {
                $delivery = self::deliveryOf($span);
                if ($delivery === null || $span->status === SpanStatus::Error) {
                    continue;
                }
                [$channel, $messageId] = $delivery;
                if ($span->kind === SpanKind::Producer) {
                    $sentDeliveries[$channel][$messageId] = ($sentDeliveries[$channel][$messageId] ?? 0) + 1;
                } elseif ($span->kind === SpanKind::Consumer) {
                    $processedDeliveries[$channel][$messageId] = ($processedDeliveries[$channel][$messageId] ?? 0) + 1;
                }

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

        return new TraceProgress($traceId, true, $rootEnded, $sent, $processed, $failed, $sentDeliveries, $processedDeliveries);
    }

    public static function destinationOf(Span $span): ?string
    {
        $destination = $span->attributes[SpanAttribute::ENDPOINT_ID] ?? $span->attributes[SpanAttribute::PROJECTION_NAME] ?? null;

        return $destination === null ? null : (string) $destination;
    }

    /**
     * @return array{string, string}|null
     */
    private static function deliveryOf(Span $span): ?array
    {
        $channel = $span->attributes[SpanAttribute::MESSAGING_DESTINATION_NAME] ?? null;
        $messageId = $span->attributes[MessageHeaders::MESSAGE_ID] ?? null;

        return $channel === null || $messageId === null ? null : [(string) $channel, (string) $messageId];
    }
}
