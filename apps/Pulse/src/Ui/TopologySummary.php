<?php

declare(strict_types=1);

namespace App\Ui;

use App\Graph\Mismatch;
use App\Graph\TopologyReport;

final readonly class TopologySummary
{
    private const SEVERITY_BY_KIND = [
        'unmapped-command-target' => Status::Critical,
        'silent-command-target' => Status::Warning,
        'unconfigured-command-target' => Status::Warning,
        'unsubscribed-event' => Status::Warning,
        'unpublished-subscription' => Status::Warning,
    ];
    private const KEYS_IN_SUMMARY = 3;

    /**
     * @param list<array{kind: string, status: Status, text: string, where: list<array{label: string}>, evidence: string, fix: string, concept: string, mismatch: Mismatch}> $problems
     * @param list<array{from: string, to: string, channel: string, stale: bool}> $commandRoutes
     * @param list<array{service: string, stale: bool, text: string}> $eventRoutes
     * @param array{status: Status, summary: string} $commandSection
     * @param array{status: Status, summary: string} $eventSection
     */
    private function __construct(
        public Status $status,
        public ?string $reason,
        public string $sentence,
        public bool $checked,
        public bool $hasRoutes,
        public array $problems,
        public array $commandRoutes,
        public array $eventRoutes,
        public array $commandSection,
        public array $eventSection,
    ) {
    }

    /**
     * @param list<array{kind: string, message: string}> $notices
     */
    public static function of(TopologyReport $report, bool $anyServiceAnswered, array $notices): self
    {
        $problems = array_map(static fn (Mismatch $mismatch): array => [
            'kind' => $mismatch->kind,
            'status' => self::SEVERITY_BY_KIND[$mismatch->kind] ?? Status::Warning,
            'text' => $mismatch->text,
            'where' => array_map(static fn (string $place): array => ['label' => $place], $mismatch->where),
            'evidence' => $mismatch->why,
            'fix' => $mismatch->fix,
            'concept' => 'mismatch',
            'mismatch' => $mismatch,
        ], $report->mismatches);
        usort($problems, static fn (array $a, array $b): int => $b['status']->weight() <=> $a['status']->weight());

        $commandRoutes = $report->commandRoutes;
        usort($commandRoutes, static fn (array $a, array $b): int => $b['stale'] <=> $a['stale']);
        $eventRoutes = array_map(static fn (array $subscription): array => [
            'service' => $subscription['service'],
            'stale' => $subscription['stale'],
            'text' => self::eventRouteText($subscription, $report->serviceOnChannel($subscription['channel'])),
        ], $report->eventSubscriptions);
        usort($eventRoutes, static fn (array $a, array $b): int => $b['stale'] <=> $a['stale']);

        $hasRoutes = $commandRoutes !== [] || $eventRoutes !== [];
        $staleServices = array_values(array_unique([
            ...array_map(static fn (array $route): string => $route['from'], array_filter($commandRoutes, static fn (array $route): bool => $route['stale'])),
            ...array_map(static fn (array $route): string => $route['service'], array_filter($eventRoutes, static fn (array $route): bool => $route['stale'])),
        ]));
        $routes = sprintf('%s and %s', self::counted(count($commandRoutes), 'command route', 'command routes'), self::counted(count($eventRoutes), 'event route', 'event routes'));
        $status = Status::worstOf(...array_map(static fn (array $problem): Status => $problem['status'], $problems), ...($notices === [] ? [] : [Status::Warning]));
        $reason = null;

        if (! $anyServiceAnswered) {
            $status = Status::Unknown;
            $reason = 'no service has answered Pulse with its Service Map yet';
            $sentence = 'Pulse has no Service Map to compare yet.';
        } elseif ($problems !== []) {
            $sentence = sprintf('%s between what the services send and where their Service Maps route it.', self::counted(count($problems), 'mismatch', 'mismatches'));
        } elseif ($report->isIncomplete()) {
            $status = Status::worstOf($status, Status::Unknown);
            $reason = $status === Status::Unknown ? self::listed($report->incompleteBecause) . (count($report->incompleteBecause) === 1 ? ' is' : ' are') . ' not answering' : null;
            $sentence = sprintf('No command mismatches in %s; events are not compared yet.', $routes);
        } elseif (! $hasRoutes) {
            $sentence = 'No service routes commands or events to another service over the Distributed Bus.';
        } elseif ($staleServices !== []) {
            $sentence = sprintf('No mismatches in %s, but %s %s stale, so the routes may have changed.', $routes, self::listed($staleServices), count($staleServices) === 1 ? 'is' : 'are');
        } else {
            $sentence = sprintf('No mismatches in %s.', $routes);
        }

        $staleCommands = count(array_filter($commandRoutes, static fn (array $route): bool => $route['stale']));
        $staleEvents = count(array_filter($eventRoutes, static fn (array $route): bool => $route['stale']));
        $keys = array_values(array_unique(array_merge(...array_map(static fn (array $subscription): array => $subscription['keys'], $report->eventSubscriptions) ?: [[]])));

        return new self(
            $status,
            $reason,
            $sentence,
            $anyServiceAnswered && $hasRoutes && ! $report->isIncomplete(),
            $hasRoutes,
            $problems,
            $commandRoutes,
            $eventRoutes,
            [
                'status' => $staleCommands > 0 ? Status::Warning : Status::Ok,
                'summary' => count($commandRoutes) . ' · ' . ($commandRoutes === [] ? 'none mapped' : self::freshness($staleCommands)),
            ],
            [
                'status' => $staleEvents > 0 ? Status::Warning : Status::Ok,
                'summary' => count($eventRoutes) . ' · ' . ($eventRoutes === [] ? 'none mapped' : self::briefly($keys) . ' · ' . self::freshness($staleEvents)),
            ],
        );
    }

    /**
     * @param array{service: string, channel: string, keys: list<string>, stale: bool, include: list<string>, exclude: list<string>} $subscription
     */
    private static function eventRouteText(array $subscription, ?string $subscriber): string
    {
        $text = sprintf(
            '%s Service Map sends the events it publishes matching %s to %sover channel %s.',
            self::possessive($subscription['service']),
            implode(', ', $subscription['keys']),
            $subscriber === null ? '' : $subscriber . ' ',
            $subscription['channel'],
        );
        if ($subscription['include'] !== []) {
            $text .= sprintf(' Only events published by %s.', self::listed($subscription['include']));
        }
        if ($subscription['exclude'] !== []) {
            $text .= sprintf(' Not events published by %s.', self::listed($subscription['exclude']));
        }

        return $text;
    }

    private static function freshness(int $stale): string
    {
        return $stale === 0 ? 'all up to date' : $stale . ' stale';
    }

    private static function possessive(string $name): string
    {
        return str_ends_with($name, 's') ? $name . "'" : $name . "'s";
    }

    /**
     * @param list<string> $items
     */
    private static function briefly(array $items): string
    {
        return count($items) <= self::KEYS_IN_SUMMARY ? implode(', ', $items) : sprintf('%s and %d more', implode(', ', array_slice($items, 0, self::KEYS_IN_SUMMARY)), count($items) - self::KEYS_IN_SUMMARY);
    }

    private static function counted(int $count, string $one, string $many): string
    {
        return $count . ' ' . ($count === 1 ? $one : $many);
    }

    /**
     * @param list<string> $items
     */
    private static function listed(array $items): string
    {
        if (count($items) <= 1) {
            return implode('', $items);
        }

        return implode(', ', array_slice($items, 0, -1)) . ' and ' . $items[count($items) - 1];
    }
}
