<?php

declare(strict_types=1);

namespace App\Ui;

use App\DeadLetter\DeadLetterFilter;
use App\DeadLetter\DeadLetterOverview;
use App\Snapshot\ServiceSnapshot;
use App\Trace\FlowTypeOverview;

final readonly class CatalogueSummary
{
    public const KINDS = ['command', 'event', 'query'];
    private const NOUNS = ['command' => ['command', 'commands'], 'event' => ['event', 'events'], 'query' => ['query', 'queries']];

    /**
     * @param list<array{service: string, greyed: bool, counts: string, groups: list<array<string, mixed>>}> $services
     * @param list<array{kind: string, label: string, count: int, current: bool}> $kinds
     * @param list<array{service: string, counts: string}> $serviceCounts
     */
    private function __construct(
        public Status $status,
        public ?string $reason,
        public string $sentence,
        public int $inferred,
        public string $kind,
        public array $kinds,
        public array $serviceCounts,
        public array $services,
    ) {
    }

    /**
     * @param list<array{service: string, kind: string, aggregate: ?string, greyed: bool, entries: list<array<string, mixed>>}> $groups
     * @param list<ServiceSnapshot> $snapshots
     * @param list<array{kind: string, message: string}> $notices
     */
    public static function of(array $groups, int $known, array $snapshots, bool $anyServiceAnswered, array $notices, string $query, string $kind, string $selected = ''): self
    {
        usort($groups, static fn (array $a, array $b): int => $a['greyed'] <=> $b['greyed']);
        $kind = in_array($kind, self::KINDS, true) ? $kind : '';
        $query = trim($query);
        $flowTypes = self::flowTypesByService($snapshots);
        $deadLetters = self::deadLettersByService($snapshots);
        $open = $query !== '' || $kind !== '';

        $counts = array_fill_keys(self::KINDS, 0);
        $distinct = array_fill_keys(self::KINDS, []);
        $byService = [];
        $inferred = 0;
        $explainedInferred = false;
        $explainedAggregate = false;
        foreach ($groups as $group) {
            $groupCounts = array_fill_keys(self::KINDS, 0);
            $entries = [];
            foreach ($group['entries'] as $entry) {
                $entryKind = (string) $entry['kind'];
                $groupCounts[$entryKind] = ($groupCounts[$entryKind] ?? 0) + 1;
                $counts[$entryKind] = ($counts[$entryKind] ?? 0) + 1;
                $distinct[$entryKind][(string) $entry['routingKey']][(string) $entry['service']] = true;
                $inferred += $entry['inferred'] === true ? 1 : 0;
                if ($kind !== '' && $entryKind !== $kind) {
                    continue;
                }
                $explain = $entry['inferred'] === true && ! $explainedInferred;
                $explainedInferred = $explainedInferred || $explain;
                $entries[$entryKind][] = $entry + [
                    'explainInferred' => $explain,
                    'traces' => self::tracesOf($flowTypes[(string) $entry['service']] ?? [], $entry),
                    'deadLetters' => self::deadLettersOf($deadLetters[(string) $entry['service']] ?? [], $entry),
                ];
            }
            $service = (string) $group['service'];
            $byService[$service] ??= ['service' => $service, 'greyed' => $group['greyed'], 'counts' => array_fill_keys(self::KINDS, 0), 'groups' => []];
            foreach ($groupCounts as $name => $count) {
                $byService[$service]['counts'][$name] += $count;
            }
            if ($entries === []) {
                continue;
            }
            $explainAggregate = $group['aggregate'] !== null && ! $explainedAggregate;
            $explainedAggregate = $explainedAggregate || $explainAggregate;
            $byService[$service]['groups'][] = [
                'kind' => $group['kind'],
                'service' => $service,
                'aggregate' => $group['aggregate'],
                'greyed' => $group['greyed'],
                'key' => 'catalogue.' . $service . '.' . ($group['aggregate'] ?? 'services'),
                'title' => $group['aggregate'] === null ? 'Services and handlers' : $group['aggregate'] . ' aggregate',
                'summary' => self::countsText($groupCounts),
                'open' => $open,
                'explainAggregate' => $explainAggregate,
                'byKind' => array_values(array_filter(array_map(static fn (string $name): array => [
                    'kind' => $name,
                    'label' => self::counted(count($entries[$name] ?? []), ...self::NOUNS[$name]),
                    'entries' => self::sorted($entries[$name] ?? []),
                ], self::KINDS), static fn (array $list): bool => $list['entries'] !== [])),
            ];
        }

        $total = array_sum($counts);
        $services = array_values(array_map(static fn (array $service): array => [
            'service' => $service['service'],
            'greyed' => $service['greyed'],
            'counts' => self::countsText($service['counts']),
            'groups' => $service['groups'],
        ], array_filter($byService, static fn (array $service): bool => $service['groups'] !== [])));
        $status = $notices === [] ? Status::Ok : Status::Warning;
        $reason = null;
        if (! $anyServiceAnswered) {
            $status = Status::Unknown;
            $reason = 'no service has answered Pulse with its flow graph yet';
            $sentence = 'Pulse has no messages to list yet.';
        } elseif ($total === 0) {
            $sentence = $query === '' ? 'No service here declares a command, event or query handler yet.' : sprintf('No message matches "%s".', $query);
        } elseif ($query !== '') {
            $sentence = sprintf('%d of %d messages match "%s": %s.', $total, $known, $query, self::kindsSentence($counts));
        } elseif ($selected !== '' && isset($byService[$selected])) {
            $own = $byService[$selected]['counts'];
            $neighbours = array_values(array_diff(array_keys($byService), [$selected]));
            $sentence = sprintf(
                '%s in %s: %s.%s',
                self::counted(array_sum($own), 'message', 'messages'),
                $selected,
                self::kindsSentence($own),
                $neighbours === [] ? '' : sprintf(' %s, which it talks to, %s greyed.', implode(' and ', $neighbours), count($neighbours) === 1 ? 'is shown' : 'are shown'),
            );
        } else {
            $distinctCounts = array_map('count', $distinct);
            $shared = 0;
            foreach ($distinct as $keys) {
                $shared += count(array_filter($keys, static fn (array $services): bool => count($services) > 1));
            }
            $sentence = sprintf(
                '%s in %s: %s%s.',
                self::counted(array_sum($distinctCounts), 'message', 'messages'),
                self::counted(count($byService), 'service', 'services'),
                self::kindsSentence($distinctCounts),
                $shared === 0 ? '' : sprintf('; %d %s listed in %s', $shared, $shared === 1 ? 'is' : 'are', count($byService) === 2 ? 'both services' : 'more than one service'),
            );
        }

        return new self(
            $status,
            $reason,
            $sentence,
            $inferred,
            $kind,
            [
                ['kind' => '', 'label' => 'All', 'count' => $total, 'current' => $kind === ''],
                ...array_map(static fn (string $name): array => ['kind' => $name, 'label' => ucfirst(self::NOUNS[$name][1]), 'count' => $counts[$name], 'current' => $kind === $name], self::KINDS),
            ],
            array_values(array_map(static fn (array $service): array => ['service' => $service['service'], 'counts' => self::countsText($service['counts'])], $byService)),
            $services,
        );
    }

    /**
     * @param list<ServiceSnapshot> $snapshots
     * @return array<string, array<string, array{service: string, flowType: string, runs: int}>>
     */
    private static function flowTypesByService(array $snapshots): array
    {
        $byService = [];
        foreach (FlowTypeOverview::of($snapshots) as $service) {
            foreach ($service['flowTypes'] as $flowType) {
                $name = (string) ($flowType['flowType'] ?? '');
                if ($name === '' || isset($byService[$service['name']][$name])) {
                    continue;
                }
                $byService[$service['name']][$name] = ['service' => $service['service'], 'flowType' => $name, 'runs' => (int) ($flowType['runs'] ?? 0)];
            }
        }

        return $byService;
    }

    /**
     * @param list<ServiceSnapshot> $snapshots
     * @return array<string, array<string, int>>
     */
    private static function deadLettersByService(array $snapshots): array
    {
        $byService = [];
        foreach (DeadLetterOverview::of($snapshots, new DeadLetterFilter('', '', '', '', '', '', '')) as $service) {
            foreach ($service['groups'] as $group) {
                $name = (string) ($group['messageName'] ?? '');
                if ($name !== '') {
                    $byService[$service['name']][$name] = ($byService[$service['name']][$name] ?? 0) + (int) ($group['count'] ?? 0);
                }
            }
        }

        return $byService;
    }

    /**
     * @param array<string, array{service: string, flowType: string, runs: int}> $flowTypes
     * @param array<string, mixed> $entry
     * @return array{service: string, flowType: string, runs: int}|null
     */
    private static function tracesOf(array $flowTypes, array $entry): ?array
    {
        return $flowTypes[(string) $entry['routingKey']] ?? $flowTypes[(string) $entry['name']] ?? null;
    }

    /**
     * @param array<string, int> $deadLetters
     * @param array<string, mixed> $entry
     * @return array{messageName: string, count: int}|null
     */
    private static function deadLettersOf(array $deadLetters, array $entry): ?array
    {
        foreach ([(string) $entry['routingKey'], (string) $entry['name']] as $name) {
            if (($deadLetters[$name] ?? 0) > 0) {
                return ['messageName' => $name, 'count' => $deadLetters[$name]];
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>>
     */
    private static function sorted(array $entries): array
    {
        usort($entries, static fn (array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']) ?: strcmp((string) $a['routingKey'], (string) $b['routingKey']));

        return $entries;
    }

    /**
     * @param array<string, int> $counts
     */
    private static function countsText(array $counts): string
    {
        $parts = [];
        foreach (self::KINDS as $name) {
            if (($counts[$name] ?? 0) > 0) {
                $parts[] = self::counted($counts[$name], ...self::NOUNS[$name]);
            }
        }

        return $parts === [] ? 'no messages' : implode(' · ', $parts);
    }

    /**
     * @param array<string, int> $counts
     */
    private static function kindsSentence(array $counts): string
    {
        $parts = [];
        foreach (self::KINDS as $name) {
            if (($counts[$name] ?? 0) > 0) {
                $parts[] = self::counted($counts[$name], ...self::NOUNS[$name]);
            }
        }
        $last = array_pop($parts);

        return $parts === [] ? (string) $last : implode(', ', $parts) . ' and ' . $last;
    }

    private static function counted(int $count, string $one, string $many): string
    {
        return $count . ' ' . ($count === 1 ? $one : $many);
    }
}
