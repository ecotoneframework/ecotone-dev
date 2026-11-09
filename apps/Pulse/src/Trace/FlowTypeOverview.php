<?php

declare(strict_types=1);

namespace App\Trace;

use App\Graph\ServiceFlow;
use App\Snapshot\ServiceSnapshot;
use Ecotone\Api\Messaging\Flow\FlowNodeKind;

final class FlowTypeOverview
{
    /**
     * @param list<ServiceSnapshot> $snapshots
     * @return list<array{service: string, name: string, tenant: ?string, status: ServiceSnapshot, tracing: bool, statsWindow: int, retentionSeconds: ?int, flowTypes: list<array<string, mixed>>}>
     */
    public static function of(array $snapshots, string $scope = ''): array
    {
        $services = [];
        foreach ($snapshots as $snapshot) {
            if ($scope !== '' && $snapshot->scope() !== $scope && $snapshot->service !== $scope) {
                continue;
            }
            $tracing = $snapshot->snapshot['tracing'] ?? null;
            $enabled = is_array($tracing);
            $flowTypes = $enabled ? array_values(array_filter($tracing['flowTypes'] ?? [], is_array(...))) : [];
            usort($flowTypes, fn (array $first, array $second): int => strcmp((string) ($first['flowType'] ?? ''), (string) ($second['flowType'] ?? '')));
            $kinds = $flowTypes === [] ? [] : self::messageKindsOf($snapshot);
            $services[] = [
                'service' => $snapshot->scope(),
                'name' => $snapshot->service,
                'tenant' => $snapshot->tenant,
                'status' => $snapshot,
                'tracing' => $enabled,
                'statsWindow' => $enabled && is_int($tracing['statsWindow'] ?? null) ? $tracing['statsWindow'] : 0,
                'retentionSeconds' => $enabled && is_int($tracing['retention'] ?? null) ? $tracing['retention'] : null,
                'flowTypes' => array_map(static fn (array $flowType): array => self::withErrorRate($flowType) + ['kind' => $kinds[(string) ($flowType['flowType'] ?? '')] ?? null], $flowTypes),
            ];
        }

        return $services;
    }

    /**
     * @return array<string, string>
     */
    private static function messageKindsOf(ServiceSnapshot $snapshot): array
    {
        $flow = ServiceFlow::fromSnapshot($snapshot);
        if ($flow === null) {
            return [];
        }
        $kinds = [];
        foreach ($flow->graph->nodesOfKind(FlowNodeKind::COMMAND, FlowNodeKind::EVENT, FlowNodeKind::QUERY) as $message) {
            $kinds[ServiceFlow::routingKeyOf($message)] ??= $message->kind->value;
            $kinds[$message->label] ??= $message->kind->value;
        }

        return $kinds;
    }

    /**
     * @param array<string, mixed> $flowType
     * @return array<string, mixed>
     */
    private static function withErrorRate(array $flowType): array
    {
        $window = (int) ($flowType['windowRuns'] ?? 0);
        $rate = $window === 0 ? 0.0 : round(100 * (int) ($flowType['runsWithErrors'] ?? 0) / $window, 1);
        $flowType['errorRate'] = rtrim(rtrim(number_format($rate, 1, '.', ''), '0'), '.') . '%';
        $flowType['errorRatio'] = $rate;

        return $flowType;
    }
}
