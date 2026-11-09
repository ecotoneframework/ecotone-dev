<?php

declare(strict_types=1);

namespace App\Ui;

use App\Home\ProblemBoard;
use App\Snapshot\ChannelDepth;
use App\Snapshot\ServiceSnapshot;
use App\Snapshot\ServiceStatus;
use App\Snapshot\Snapshots;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class ServiceTable
{
    private const NAMED_IN_VERDICT = 2;

    public function __construct(private ProblemBoard $board, private HomeOverview $home, private ServiceOverview $overview, private UrlGeneratorInterface $urls)
    {
    }

    /**
     * @param list<ServiceSnapshot> $services
     * @param list<ServiceSnapshot> $scopes
     * @return list<ServiceRow>
     */
    public function rows(array $services, array $scopes): array
    {
        $versions = Snapshots::ecotoneVersions($services);
        $newest = $versions === [] ? null : $versions[array_key_last($versions)];
        $linesOf = [];
        foreach ($this->home->lines($this->board->tiles($scopes, $scopes)) as $line) {
            $linesOf[$line->tile->scope->service][] = $line;
        }

        $rows = array_map(fn (ServiceSnapshot $service): ServiceRow => $this->rowOf($service, $linesOf[$service->service] ?? [], $newest), $services);
        usort($rows, static fn (ServiceRow $first, ServiceRow $second): int => [-$first->status()->weight(), -$first->problemCount(), $first->service->service]
            <=> [-$second->status()->weight(), -$second->problemCount(), $second->service->service]);

        return $rows;
    }

    /**
     * @param list<ServiceRow> $rows
     */
    public function verdict(array $rows): Verdict
    {
        if ($rows === []) {
            return new Verdict(Status::Info, 'No service is configured, so Pulse has nobody to compare.', false);
        }
        $status = Status::worstOf(...array_map(static fn (ServiceRow $row): Status => $row->status(), $rows));
        $clauses = [$this->connectionClause($rows)];
        $versions = $this->versionClause($rows);
        if ($versions !== null) {
            $clauses[] = $versions;
        }
        $attention = array_values(array_filter($rows, static fn (ServiceRow $row): bool => $row->needsAttention()));
        if ($attention !== []) {
            $named = array_map(static fn (ServiceRow $row): string => $row->service->service, array_slice($attention, 0, self::NAMED_IN_VERDICT));
            $rest = count($attention) - count($named);
            $clauses[] = count($attention) === 1
                ? sprintf('%s needs attention', $named[0])
                : sprintf('%d need attention: %s%s', count($attention), implode(', ', $named), $rest > 0 ? sprintf(' and %d more', $rest) : '');
        }

        return new Verdict($status, implode('; ', $clauses) . '.', $status === Status::Ok);
    }

    /**
     * @param list<ServiceRow> $rows
     * @return array{connected: int, unreachable: int, drift: int}
     */
    public static function summary(array $rows): array
    {
        return [
            'connected' => count(array_filter($rows, static fn (ServiceRow $row): bool => $row->service->status === ServiceStatus::Connected)),
            'unreachable' => count(array_filter($rows, static fn (ServiceRow $row): bool => in_array($row->service->status, [ServiceStatus::Unreachable, ServiceStatus::ChannelMissing], true))),
            'drift' => count(array_filter($rows, static fn (ServiceRow $row): bool => $row->newerEcotone !== null)),
        ];
    }

    /**
     * @param list<ServiceLine> $lines
     */
    private function rowOf(ServiceSnapshot $service, array $lines, ?string $newest): ServiceRow
    {
        $scopes = array_map(static fn (ServiceLine $line): ServiceSnapshot => $line->tile->scope, $lines);
        $tenantChannels = [];
        if ($service->isMultiTenant()) {
            foreach ($scopes as $scope) {
                $tenantChannels = [...$tenantChannels, ...array_values(array_filter($this->overview->channelsOf($scope), static fn (ChannelDepth $depth): bool => $depth->perTenant))];
            }
        }
        $projections = ['total' => 0, 'behind' => 0, 'stalled' => 0, 'failed' => 0];
        foreach ($scopes as $scope) {
            foreach ($this->overview->projectionCounts($scope) as $kind => $count) {
                $projections[$kind] += $count;
            }
        }
        $version = $service->ecotoneVersion();
        $own = ['service' => $service->service];

        return new ServiceRow(
            service: $service,
            lines: $lines,
            channels: $this->overview->channelsOf($service),
            tenantChannels: $tenantChannels,
            failedMessages: self::sumOf($scopes, static fn (ServiceSnapshot $scope): ?int => self::storesDeadLetters($scope) ? $scope->deadLetterTotal() : null),
            failureGroups: self::sumOf($scopes, static fn (ServiceSnapshot $scope): ?int => self::storesDeadLetters($scope) ? $scope->failureCount() : null),
            projections: $projections,
            newerEcotone: $newest !== null && $version !== null && $version !== $newest ? $newest : null,
            links: [
                'hub' => $this->urls->generate('service_hub', $own),
                'failures' => $this->urls->generate('dead_letters', $own),
                'channels' => $this->urls->generate('service_channels', $own),
                'projections' => $this->urls->generate('service_projections', $own),
                'configuration' => $this->urls->generate('service_configuration', $own),
            ],
        );
    }

    /**
     * @param list<ServiceRow> $rows
     */
    private function connectionClause(array $rows): string
    {
        $count = count($rows);
        $noun = $count === 1 ? 'service' : 'services';
        $states = [];
        foreach ($rows as $row) {
            $label = match ($row->service->status) {
                ServiceStatus::Connected => 'connected',
                ServiceStatus::Waiting => 'waiting for an answer',
                ServiceStatus::Unreachable => 'unreachable',
                ServiceStatus::ChannelMissing => 'missing its request channel',
            };
            $states[$label] = ($states[$label] ?? 0) + 1;
        }
        if (array_keys($states) === ['connected']) {
            return sprintf('%d %s, %s', $count, $noun, match ($count) {
                1 => 'connected',
                2 => 'both connected',
                default => 'all connected',
            });
        }

        return sprintf('%d %s: %s', $count, $noun, implode(', ', array_map(static fn (string $label, int $number): string => $number . ' ' . $label, array_keys($states), $states)));
    }

    /**
     * @param list<ServiceRow> $rows
     */
    private function versionClause(array $rows): ?string
    {
        $versions = Snapshots::ecotoneVersions(array_map(static fn (ServiceRow $row): ServiceSnapshot => $row->service, $rows));
        if ($versions === []) {
            return null;
        }
        $newest = $versions[array_key_last($versions)];
        if (count($versions) === 1) {
            return count($rows) === 1 ? sprintf('it runs Ecotone %s', $newest) : 'Ecotone versions match';
        }
        $behind = array_values(array_filter($rows, static fn (ServiceRow $row): bool => $row->newerEcotone !== null));

        return count($behind) === 1
            ? sprintf('%s runs an older Ecotone than %s', $behind[0]->service->service, $newest)
            : sprintf('%d services run an older Ecotone than %s', count($behind), $newest);
    }

    private static function storesDeadLetters(ServiceSnapshot $scope): bool
    {
        return ($scope->snapshot['deadLetter']['enabled'] ?? null) !== false;
    }

    /**
     * @param list<ServiceSnapshot> $scopes
     * @param callable(ServiceSnapshot): ?int $count
     */
    private static function sumOf(array $scopes, callable $count): ?int
    {
        $known = array_filter(array_map($count, $scopes), static fn (?int $value): bool => $value !== null);

        return $known === [] ? null : array_sum($known);
    }
}
