<?php

declare(strict_types=1);

namespace App\Projection;

use App\Snapshot\ServiceSnapshot;
use DateTimeImmutable;

final class ProjectionOverview
{
    /**
     * @return list<ProjectionStatus>
     */
    public static function of(ServiceSnapshot $service, int $stalledAfterSeconds, ?RebuildReport $rebuilds = null): array
    {
        $projections = $service->snapshot['projections'] ?? null;
        if (! is_array($projections)) {
            return [];
        }

        $deadLetterChannels = self::deadLetterChannels($service);

        return array_values(array_map(
            fn (array $projection): ProjectionStatus => self::statusOf($projection, $service, $stalledAfterSeconds, $deadLetterChannels, $rebuilds ?? RebuildReport::none()),
            array_filter($projections, static fn (mixed $projection): bool => is_array($projection) && is_string($projection['name'] ?? null)),
        ));
    }

    /**
     * @param list<ProjectionStatus> $statuses
     * @return array{total: int, behind: int, stalled: int, failed: int}
     */
    public static function counts(array $statuses): array
    {
        return [
            'total' => count($statuses),
            'behind' => count(array_filter($statuses, static fn (ProjectionStatus $status): bool => $status->behind)),
            'stalled' => count(array_filter($statuses, static fn (ProjectionStatus $status): bool => $status->state === ProjectionState::Stalled)),
            'failed' => count(array_filter($statuses, static fn (ProjectionStatus $status): bool => $status->state === ProjectionState::Failed)),
        ];
    }

    /**
     * @param array<string, mixed> $projection
     * @param list<string> $deadLetterChannels
     */
    private static function statusOf(array $projection, ServiceSnapshot $service, int $stalledAfterSeconds, array $deadLetterChannels, RebuildReport $rebuilds): ProjectionStatus
    {
        $name = $projection['name'];
        $asyncChannel = is_string($projection['asyncChannel'] ?? null) ? $projection['asyncChannel'] : null;
        $unavailable = is_string($projection['unavailable'] ?? null) ? $projection['unavailable'] : null;
        $since = isset($service->projectionMovements[$name]['since']) ? new DateTimeImmutable($service->projectionMovements[$name]['since']) : null;
        $unmovedSeconds = $since !== null && $service->lastSeenAt !== null ? $service->lastSeenAt->getTimestamp() - $since->getTimestamp() : 0;
        [$gap, $behind] = self::gapOf($projection);

        $state = match (true) {
            $rebuilds->isRunning($name) => ProjectionState::Rebuilding,
            $unavailable !== null => ProjectionState::Unknown,
            $asyncChannel !== null && in_array($asyncChannel, $deadLetterChannels, true) => ProjectionState::Failed,
            $behind && $unmovedSeconds >= $stalledAfterSeconds => ProjectionState::Stalled,
            $behind => ProjectionState::CatchingUp,
            default => ProjectionState::UpToDate,
        };

        return new ProjectionStatus(
            $name,
            is_string($projection['mode'] ?? null) ? $projection['mode'] : 'unknown',
            ($projection['partitioned'] ?? false) === true,
            is_string($projection['initialization'] ?? null) ? $projection['initialization'] : null,
            $unavailable === null ? $gap : '—',
            $unavailable === null && $behind,
            $state,
            $since !== null && $service->lastSeenAt !== null ? $unmovedSeconds : null,
            $unavailable,
            is_string($projection['rebuildAsyncChannel'] ?? null) ? $projection['rebuildAsyncChannel'] : null,
            $rebuilds->latestOf($name),
        );
    }

    /**
     * @param array<string, mixed> $projection
     * @return array{string, bool}
     */
    private static function gapOf(array $projection): array
    {
        $partitions = $projection['partitions'] ?? null;
        if (is_array($partitions)) {
            $plus = ($partitions['atLeast'] ?? false) === true ? '+' : '';

            return [sprintf('%d%s of %d partitions behind', $partitions['behind'] ?? 0, $plus, $partitions['total'] ?? 0), ($partitions['behind'] ?? 0) > 0];
        }

        $eventsBehind = $projection['eventsBehind'] ?? null;
        if (! is_array($eventsBehind)) {
            return ['—', false];
        }
        $events = (int) ($eventsBehind['events'] ?? 0);
        if ($events === 0) {
            return ['0', false];
        }

        return [sprintf('%d%s events behind', $events, ($eventsBehind['atLeast'] ?? false) === true ? '+' : ''), true];
    }

    /**
     * @return list<string>
     */
    private static function deadLetterChannels(ServiceSnapshot $service): array
    {
        $recent = $service->snapshot['deadLetters']['recent'] ?? null;
        if (! is_array($recent)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(static fn (mixed $entry): mixed => is_array($entry) ? ($entry['channel'] ?? null) : null, $recent), 'is_string')));
    }
}
