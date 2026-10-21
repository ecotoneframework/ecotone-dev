<?php

declare(strict_types=1);

namespace App\Trace;

use App\Snapshot\ServiceSnapshot;

final class FlowTypeOverview
{
    /**
     * @param list<ServiceSnapshot> $snapshots
     * @return list<array{service: string, status: ServiceSnapshot, tracing: bool, statsWindow: int, flowTypes: list<array<string, mixed>>}>
     */
    public static function of(array $snapshots): array
    {
        $services = [];
        foreach ($snapshots as $snapshot) {
            $tracing = $snapshot->snapshot['tracing'] ?? null;
            $enabled = is_array($tracing);
            $flowTypes = $enabled ? array_values(array_filter($tracing['flowTypes'] ?? [], is_array(...))) : [];
            usort($flowTypes, fn (array $first, array $second): int => strcmp((string) ($first['flowType'] ?? ''), (string) ($second['flowType'] ?? '')));
            $services[] = [
                'service' => $snapshot->service,
                'status' => $snapshot,
                'tracing' => $enabled,
                'statsWindow' => $enabled && is_int($tracing['statsWindow'] ?? null) ? $tracing['statsWindow'] : 0,
                'flowTypes' => array_map(self::withErrorRate(...), $flowTypes),
            ];
        }

        return $services;
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

        return $flowType;
    }
}
