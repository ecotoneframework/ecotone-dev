<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Test;

use function array_keys;
use function array_map;

use Ecotone\Api\Dbal\ExtensionObject\MultiTenantConfiguration;
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
        $metadataByTrace = self::flowMetadataByTrace($scenario, $recordedSpans);
        $steps = [];
        foreach ($metadataByTrace as $traceId => $metadata) {
            $steps = [...$steps, ...($tracing->fetchFlow($traceId, $metadata)?->steps ?? [])];
        }
        $mismatch = $scenario->asStoredByFlowTracking()->mismatchAfterStage($stageIndex, $steps);
        if ($mismatch !== null) {
            return $mismatch;
        }

        $completed = array_map(fn (string $traceId): bool => $tracing->isCompletedFor($traceId, metadata: $metadataByTrace[$traceId]), array_keys($metadataByTrace));
        $finished = $scenario->isFinishedAfterStage($stageIndex);
        if ($finished && in_array(false, $completed, true)) {
            return "Stage {$stageIndex} leaves no work pending, yet a flow is not completed: " . self::pendingOf($tracing, $metadataByTrace);
        }
        if (! $finished && $completed !== [] && ! in_array(false, $completed, true)) {
            return "Stage {$stageIndex} leaves work pending or failed, yet every flow is completed";
        }

        return null;
    }

    /**
     * @param Span[] $recordedSpans
     * @return array<string, array<string, string>>
     */
    private static function flowMetadataByTrace(FlowScenario $scenario, array $recordedSpans): array
    {
        $tenantHeader = null;
        foreach ($scenario->extensionObjects as $extensionObject) {
            if ($extensionObject instanceof MultiTenantConfiguration) {
                $tenantHeader = $extensionObject->tenantHeaderName;
            }
        }
        $metadataByTrace = [];
        foreach ($recordedSpans as $span) {
            $metadataByTrace[$span->traceId] ??= [];
            if ($tenantHeader !== null && isset($span->attributes[$tenantHeader])) {
                $metadataByTrace[$span->traceId] = [$tenantHeader => (string) $span->attributes[$tenantHeader]];
            }
        }

        return $metadataByTrace;
    }

    /**
     * @param array<string, array<string, string>> $metadataByTrace
     */
    private static function pendingOf(TracingGateway $tracing, array $metadataByTrace): string
    {
        return (string) json_encode(array_map(fn (string $traceId) => $tracing->fetchFlow($traceId, $metadataByTrace[$traceId])?->pending, array_keys($metadataByTrace)));
    }
}
