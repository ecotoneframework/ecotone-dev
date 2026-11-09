<?php

declare(strict_types=1);

namespace App\Ui;

use App\Home\Severity;
use App\Home\Tile;
use App\Snapshot\ServiceStatus;

final readonly class HomeOverview
{
    public const PROBLEMS_SHOWN = 3;

    private const NAMED_IN_VERDICT = 2;

    public function __construct(private ServiceOverview $overview)
    {
    }

    /**
     * @param list<Tile> $tiles
     * @return list<ServiceLine>
     */
    public function lines(array $tiles): array
    {
        return array_map(fn (Tile $tile): ServiceLine => new ServiceLine(
            $tile,
            $this->overview->problemCards([$tile]),
            $tile->scope->hasSnapshot() ? $this->overview->scopeTiles($tile->scope) : [],
        ), $tiles);
    }

    /**
     * @param list<ServiceLine> $lines
     * @return list<ProblemCard>
     */
    public function problemCards(array $lines): array
    {
        $cards = array_merge(...array_map(static fn (ServiceLine $line): array => $line->cards, $lines));
        usort($cards, static fn (ProblemCard $first, ProblemCard $second): int => $second->severity->weight() <=> $first->severity->weight());

        return $cards;
    }

    /**
     * @param list<ServiceLine> $lines
     */
    public function verdict(array $lines): Verdict
    {
        if ($lines === []) {
            return new Verdict(Status::Info, 'No service is connected to Pulse yet.', false);
        }
        $status = Status::worstOf(...array_map(static fn (ServiceLine $line): Status => $line->status(), $lines));
        $attention = array_values(array_filter($lines, static fn (ServiceLine $line): bool => in_array($line->tile->worst(), [Severity::Critical, Severity::Warning], true)));
        [$one, $many] = $this->nounsOf($lines);
        if ($attention !== []) {
            $named = array_map(static fn (ServiceLine $line): string => sprintf('%s has %d %s', self::labelOf($line), count($line->cards), count($line->cards) === 1 ? 'problem' : 'problems'), array_slice($attention, 0, self::NAMED_IN_VERDICT));
            $rest = count($attention) - count($named);

            return new Verdict(
                $status,
                sprintf('%d of %d %s %s attention: %s%s.', count($attention), count($lines), $many, count($attention) === 1 ? 'needs' : 'need', implode(', ', $named), $rest > 0 ? sprintf(' and %d more', $rest) : ''),
                false,
            );
        }
        $waiting = count(array_filter($lines, static fn (ServiceLine $line): bool => $line->tile->scope->status !== ServiceStatus::Connected));
        if ($waiting > 0) {
            return new Verdict(
                Status::Info,
                $waiting === count($lines)
                    ? sprintf('Waiting for the first answer from %d %s.', $waiting, $waiting === 1 ? $one : $many)
                    : sprintf('No problem in the %d %s that answered; waiting for %d more.', count($lines) - $waiting, $many, $waiting),
                false,
            );
        }

        return new Verdict($status, 'All clear: ' . implode(', ', $this->checked($lines, $one, $many)) . '.', true);
    }

    /**
     * @param list<ServiceLine> $lines
     * @return list<string>
     */
    private function checked(array $lines, string $one, string $many): array
    {
        $channels = 0;
        $failed = 0;
        $projections = 0;
        foreach ($lines as $line) {
            $channels += count($this->overview->channelsOf($line->tile->scope));
            $failed += (int) $line->tile->scope->deadLetterTotal();
            $projections += $this->overview->projectionCounts($line->tile->scope)['total'];
        }

        return [
            sprintf('%d %s answered', count($lines), count($lines) === 1 ? $one : $many),
            match ($channels) {
                0 => 'no channels',
                1 => '1 channel, not stuck',
                default => sprintf('%d channels, none stuck', $channels),
            },
            $failed === 0 ? 'no failed messages' : sprintf('%d failed %s', $failed, $failed === 1 ? 'message' : 'messages'),
            match ($projections) {
                0 => 'no projections',
                1 => '1 projection, none behind',
                default => sprintf('%d projections, none behind', $projections),
            },
        ];
    }

    /**
     * @param list<ServiceLine> $lines
     * @return array{string, string}
     */
    private function nounsOf(array $lines): array
    {
        foreach ($lines as $line) {
            if ($line->tile->scope->tenant !== null) {
                return ['service or tenant', 'services and tenants'];
            }
        }

        return ['service', 'services'];
    }

    private static function labelOf(ServiceLine $line): string
    {
        $scope = $line->tile->scope;

        return $scope->tenant === null ? $scope->service : sprintf('%s (tenant %s)', $scope->service, $scope->tenant);
    }
}
