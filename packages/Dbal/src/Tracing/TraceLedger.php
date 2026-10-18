<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Tracing;

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
        $sentDeliveries = [];
        $processedDeliveries = [];
        foreach ($spans as $span) {
            if ($span->attributes[SpanAttribute::TRACE_ROOT] ?? false) {
                $rootEnded = true;
            }
            if (isset($span->attributes[SpanAttribute::BATCH_DELIVERIES], $span->attributes[SpanAttribute::CHANNEL_NAME]) && $span->kind === SpanKind::Producer && $span->status !== SpanStatus::Error) {
                $channel = (string) $span->attributes[SpanAttribute::CHANNEL_NAME];
                foreach (json_decode((string) $span->attributes[SpanAttribute::BATCH_DELIVERIES], true, flags: JSON_THROW_ON_ERROR) as $messageId => $endpointId) {
                    $sentDeliveries[$channel][(string) $messageId] = true;
                    if ($endpointId !== null) {
                        $sent[(string) $endpointId][(string) $messageId] = true;
                    }
                }

                continue;
            }
            $delivery = self::deliveryOf($span);
            if ($delivery !== null) {
                [$channel, $messageId] = $delivery;
                if ($span->kind === SpanKind::Producer && $span->status !== SpanStatus::Error) {
                    $sentDeliveries[$channel][$messageId] = true;
                } elseif ($span->kind === SpanKind::Consumer) {
                    $processedDeliveries[$channel][$messageId] = true;
                }
            }
            $destination = self::destinationOf($span);
            if ($destination === null) {
                continue;
            }
            $operation = $span->attributes[SpanAttribute::MESSAGING_OPERATION_TYPE] ?? null;
            $messageId = (string) ($span->attributes[MessageHeaders::MESSAGE_ID] ?? $span->spanId);
            if ($operation === SpanAttribute::OPERATION_SEND) {
                $sent[$destination][$messageId] = true;
            } elseif ($operation === SpanAttribute::OPERATION_PROCESS) {
                $processed[$destination][$messageId] = true;
            }
        }

        return new TraceProgress($traceId, true, $rootEnded, $sent, $processed, $sentDeliveries, $processedDeliveries);
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
        $channel = $span->attributes[SpanAttribute::CHANNEL_NAME] ?? null;
        $messageId = $span->attributes[MessageHeaders::MESSAGE_ID] ?? null;

        return $channel === null || $messageId === null ? null : [(string) $channel, (string) $messageId];
    }
}
