<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Test;

use function array_map;
use function array_unique;
use function array_values;

use Ecotone\Api\Dbal\Tracing\TracingGateway;
use Ecotone\Api\Tracing\Span;
use Ecotone\Test\Flow\FlowScenario;

use function in_array;
use function json_encode;

/**
 * licence Apache-2.0
 */
final class StoredFlowVerifier
{
    /**
     * @param Span[] $recordedSpans
     */
    public static function mismatchAfterStage(FlowScenario $scenario, int $stageIndex, TracingGateway $tracing, array $recordedSpans): ?string
    {
        $traceIds = array_values(array_unique(array_map(fn (Span $span): string => $span->traceId, $recordedSpans)));
        $steps = [];
        foreach ($traceIds as $traceId) {
            $steps = [...$steps, ...($tracing->fetchFlow($traceId)?->steps ?? [])];
        }
        $mismatch = $scenario->asStoredByFlowTracking()->mismatchAfterStage($stageIndex, $steps);
        if ($mismatch !== null) {
            return $mismatch;
        }

        $completed = array_map(fn (string $traceId): bool => $tracing->isCompletedFor($traceId), $traceIds);
        $finished = $scenario->isFinishedAfterStage($stageIndex);
        if ($finished && in_array(false, $completed, true)) {
            return "Stage {$stageIndex} leaves no work pending, yet a flow is not completed: " . self::pendingOf($tracing, $traceIds);
        }
        if (! $finished && $completed !== [] && ! in_array(false, $completed, true)) {
            return "Stage {$stageIndex} leaves work pending or failed, yet every flow is completed";
        }

        return null;
    }

    /**
     * @param string[] $traceIds
     */
    private static function pendingOf(TracingGateway $tracing, array $traceIds): string
    {
        return (string) json_encode(array_map(fn (string $traceId) => $tracing->fetchFlow($traceId)?->pending, $traceIds));
    }
}
