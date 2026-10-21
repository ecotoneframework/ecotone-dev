<?php

declare(strict_types=1);

namespace App\Home;

use App\Snapshot\ServiceSnapshot;
use App\Snapshot\Snapshots;

final readonly class ProblemBoard
{
    public function __construct(private ScopeProblems $problems)
    {
    }

    /**
     * @param list<ServiceSnapshot> $scopes
     * @return list<Tile>
     */
    public function tiles(array $scopes): array
    {
        $newest = null;
        $versions = Snapshots::ecotoneVersions($scopes);
        if ($versions !== []) {
            $newest = $versions[array_key_last($versions)];
        }

        $tiles = array_map(fn (ServiceSnapshot $scope): Tile => new Tile($scope, $this->problems->of($scope, $newest)), $scopes);
        usort($tiles, static fn (Tile $first, Tile $second): int => [(int) ! $first->isUnreachable(), -($first->worst()?->weight() ?? 0), -count($first->problems), $first->sortKey()]
            <=> [(int) ! $second->isUnreachable(), -($second->worst()?->weight() ?? 0), -count($second->problems), $second->sortKey()]);

        return $tiles;
    }

    /**
     * @param list<Tile> $tiles
     * @return array{critical: int, warning: int, healthy: int, waiting: int}
     */
    public static function summary(array $tiles): array
    {
        return [
            'critical' => count(array_filter($tiles, static fn (Tile $tile): bool => $tile->worst() === Severity::Critical)),
            'warning' => count(array_filter($tiles, static fn (Tile $tile): bool => $tile->worst() === Severity::Warning)),
            'healthy' => count(array_filter($tiles, static fn (Tile $tile): bool => $tile->isHealthy())),
            'waiting' => count(array_filter($tiles, static fn (Tile $tile): bool => $tile->isWaiting())),
        ];
    }
}
