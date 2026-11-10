<?php

declare(strict_types=1);

namespace App\Ui;

use App\Snapshot\ServiceSnapshot;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final readonly class FlowTypeList
{
    public const SHOWN = 5;
    public const STATE_ERRORS = 'errors';
    public const STATE_TRACKED = 'tracked';
    public const STATE_WAITING = 'waiting';
    public const STATE_NOTHING_YET = 'nothing-yet';
    public const STATE_NOT_ENABLED = 'not-enabled';
    public const REPORTED_FLOW_TYPES_LIMIT = 100;

    private const STATE_ORDER = [self::STATE_ERRORS, self::STATE_TRACKED, self::STATE_WAITING, self::STATE_NOTHING_YET, self::STATE_NOT_ENABLED];

    /**
     * @param list<array<string, mixed>> $services
     * @param list<array{text: string, service?: string}> $verdictParts
     * @param list<string> $verdictMeta
     * @param array{service: string, flowType: string, label: string}|null $verdictAction
     */
    private function __construct(
        public array $services,
        public Status $verdictStatus,
        public ?string $verdictReason,
        public array $verdictParts,
        public array $verdictMeta,
        public ?array $verdictAction,
    ) {
    }

    /**
     * @param list<array{service: string, name: string, tenant: ?string, status: ServiceSnapshot, tracing: bool, statsWindow: int, retentionSeconds: ?int, flowTypes: list<array<string, mixed>>}> $overview
     */
    public static function of(array $overview, DateTimeImmutable $now, ?string $unmatchedScopeReason = null): self
    {
        $sections = array_map(static fn (array $service): array => self::section($service, $now), $overview);
        usort($sections, static fn (array $left, array $right): int => [array_search($left['state'], self::STATE_ORDER, true), ...self::errorUrgencyOf($left)] <=> [array_search($right['state'], self::STATE_ORDER, true), ...self::errorUrgencyOf($right)]);

        if ($sections === []) {
            return new self(
                [],
                Status::Unknown,
                $unmatchedScopeReason ?? 'no service is configured in PULSE_SERVICES',
                [['text' => 'Pulse has no traces to show.']],
                [],
                null,
            );
        }

        return self::withVerdict($sections);
    }

    /**
     * @param array<string, mixed> $section
     * @return list<int>
     */
    private static function errorUrgencyOf(array $section): array
    {
        $worst = self::mostFailingOf($section);
        if ($worst === null) {
            return [0, 0, 0];
        }

        return [-$worst['runsWithErrors'], self::descending($worst['lastStartedAt']), -$section['failing']];
    }

    /**
     * @param array<string, mixed> $section
     * @return array<string, mixed>|null
     */
    private static function mostFailingOf(array $section): ?array
    {
        $worst = null;
        foreach ([...$section['shown'], ...$section['more']] as $row) {
            if ($row['runsWithErrors'] > 0 && ($worst === null || [$row['runsWithErrors'], -self::descending($row['lastStartedAt'])] > [$worst['runsWithErrors'], -self::descending($worst['lastStartedAt'])])) {
                $worst = $row;
            }
        }

        return $worst;
    }

    /**
     * @param array{service: string, name: string, tenant: ?string, status: ServiceSnapshot, tracing: bool, statsWindow: int, retentionSeconds: ?int, flowTypes: list<array<string, mixed>>} $service
     * @return array<string, mixed>
     */
    private static function section(array $service, DateTimeImmutable $now): array
    {
        $rows = array_map(static fn (array $flowType): array => self::row($flowType, $now), $service['flowTypes']);
        usort($rows, static fn (array $left, array $right): int => self::urgencyOf($left) <=> self::urgencyOf($right));
        $failing = count(array_filter($rows, static fn (array $row): bool => $row['runsWithErrors'] > 0));
        $slowest = null;
        foreach ($rows as $row) {
            if ($slowest === null || $row['p95Ms'] > $slowest['p95Ms']) {
                $slowest = $row;
            }
        }
        $state = match (true) {
            ! $service['status']->hasSnapshot() => self::STATE_WAITING,
            ! $service['tracing'] => self::STATE_NOT_ENABLED,
            $rows === [] => self::STATE_NOTHING_YET,
            $failing > 0 => self::STATE_ERRORS,
            default => self::STATE_TRACKED,
        };
        $more = array_slice($rows, self::SHOWN);
        $moreQueries = count(array_filter($more, static fn (array $row): bool => $row['kind'] === 'query'));
        $moreFailing = count(array_filter($more, static fn (array $row): bool => $row['runsWithErrors'] > 0));

        return [
            'service' => $service['service'],
            'name' => $service['name'],
            'tenant' => $service['tenant'],
            'status' => $service['status'],
            'state' => $state,
            'sectionStatus' => match ($state) {
                self::STATE_ERRORS => Status::Warning,
                self::STATE_WAITING => Status::Unknown,
                default => Status::Ok,
            },
            'sectionReason' => $state === self::STATE_WAITING ? sprintf('Pulse has no snapshot of %s yet (%s)', $service['service'], strtolower($service['status']->status->name)) : null,
            'count' => count($rows),
            'failing' => $failing,
            'summary' => match ($state) {
                self::STATE_ERRORS => sprintf('%d with errors recorded', $failing) . self::slowestPart($slowest),
                self::STATE_TRACKED => 'no errors recorded' . self::slowestPart($slowest),
                self::STATE_WAITING => 'waiting for the first answer',
                self::STATE_NOTHING_YET => 'flow tracking on, no flow tracked yet',
                default => 'flow tracking off',
            },
            'slowest' => $slowest,
            'shown' => array_slice($rows, 0, self::SHOWN),
            'more' => $more,
            'moreSummary' => $more === [] ? '' : implode(' · ', array_filter([
                count($more) . ' more',
                $moreFailing > 0 ? sprintf('%d with errors recorded', $moreFailing) : 'no errors recorded',
                $moreQueries > 0 ? sprintf('%d %s', $moreQueries, $moreQueries === 1 ? 'query' : 'queries') : null,
            ])),
            'statsWindow' => $service['statsWindow'],
            'retention' => $service['retentionSeconds'] === null ? null : self::durationOf($service['retentionSeconds']),
            'reportsLimit' => count($rows) >= self::REPORTED_FLOW_TYPES_LIMIT,
        ];
    }

    /**
     * @param array<string, mixed> $flowType
     * @return array{flowType: string, shortName: string, kind: ?string, runs: int, windowRuns: int, runsWithErrors: int, errorRate: string, errorRatio: float, p50Ms: int, p95Ms: int, maxMs: int, lastStartedAt: string, lastRunUtc: string, lastRunAgo: string}
     */
    private static function row(array $flowType, DateTimeImmutable $now): array
    {
        $name = (string) ($flowType['flowType'] ?? '');
        $lastStartedAt = is_string($flowType['lastStartedAt'] ?? null) ? $flowType['lastStartedAt'] : '';
        [$utc, $ago] = self::timeOf($lastStartedAt, $now);

        return [
            'flowType' => $name,
            'shortName' => self::shortName($name),
            'kind' => is_string($flowType['kind'] ?? null) ? $flowType['kind'] : null,
            'runs' => (int) ($flowType['runs'] ?? 0),
            'windowRuns' => (int) ($flowType['windowRuns'] ?? 0),
            'runsWithErrors' => (int) ($flowType['runsWithErrors'] ?? 0),
            'errorRate' => (string) ($flowType['errorRate'] ?? '0%'),
            'errorRatio' => (float) ($flowType['errorRatio'] ?? 0.0),
            'p50Ms' => (int) ($flowType['p50Ms'] ?? 0),
            'p95Ms' => (int) ($flowType['p95Ms'] ?? 0),
            'maxMs' => (int) ($flowType['maxMs'] ?? 0),
            'lastStartedAt' => $lastStartedAt,
            'lastRunUtc' => $utc,
            'lastRunAgo' => $ago,
        ];
    }

    /**
     * @param array{flowType: string, kind: ?string, runsWithErrors: int, errorRatio: float, p95Ms: int, lastStartedAt: string} $row
     * @return list<int|float|string>
     */
    private static function urgencyOf(array $row): array
    {
        $failing = $row['runsWithErrors'] > 0;

        return [$failing ? 0 : 1, -$row['errorRatio'], ! $failing && $row['kind'] === 'query' ? 1 : 0, -$row['p95Ms'], self::descending($row['lastStartedAt']), $row['flowType']];
    }

    private static function descending(string $at): int
    {
        try {
            return -(int) (new DateTimeImmutable($at))->format('Uu');
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @param list<array<string, mixed>> $sections
     */
    private static function withVerdict(array $sections): self
    {
        $of = static fn (string ...$states): array => array_values(array_filter($sections, static fn (array $section): bool => in_array($section['state'], $states, true)));
        $traced = $of(self::STATE_ERRORS, self::STATE_TRACKED);
        $failing = $of(self::STATE_ERRORS);
        $waiting = array_map(static fn (array $section): string => $section['service'], $of(self::STATE_WAITING));
        $nothingYet = array_map(static fn (array $section): string => $section['service'], $of(self::STATE_NOTHING_YET));
        $off = array_map(static fn (array $section): string => $section['service'], $of(self::STATE_NOT_ENABLED));
        $status = Status::worstOf(...array_map(static fn (array $section): Status => $section['sectionStatus'], $sections));
        $reason = $status === Status::Unknown
            ? ($waiting !== []
                ? sprintf('Pulse has not received a snapshot of %s yet, so it cannot tell which flows ran there.', self::listOf($waiting))
                : sprintf('flow tracking is not enabled in %s', self::listOf($off)))
            : null;
        $meta = [];

        if ($traced !== []) {
            $count = array_sum(array_map(static fn (array $section): int => $section['count'], $traced));
            $slowest = null;
            foreach ($traced as $section) {
                if ($slowest === null || $section['slowest']['p95Ms'] > $slowest['row']['p95Ms']) {
                    $slowest = ['row' => $section['slowest'], 'service' => $section['service']];
                }
            }
            $meta[] = sprintf('%d %s in %d %s', $count, $count === 1 ? 'flow type' : 'flow types', count($traced), count($traced) === 1 ? 'service' : 'services');
            $meta[] = sprintf('slowest: %s in %s, 95%% finished within %d ms', $slowest['row']['shortName'], $slowest['service'], $slowest['row']['p95Ms']);
        }
        if ($nothingYet !== []) {
            $meta[] = sprintf('no flow tracked yet in %s', self::listOf($nothingYet));
        }
        if ($off !== [] && $traced !== []) {
            $meta[] = sprintf('flow tracking off in %s', self::listOf($off));
        }

        if ($failing !== []) {
            $flowTypes = array_sum(array_map(static fn (array $section): int => $section['failing'], $failing));
            $worst = self::mostFailingOf($failing[0]) ?? $failing[0]['shown'][0];

            return new self(
                $sections,
                $status,
                $reason,
                self::perService(sprintf('%d %s with errors recorded', $flowTypes, $flowTypes === 1 ? 'flow type' : 'flow types'), $failing),
                $meta,
                ['service' => $failing[0]['service'], 'flowType' => $worst['flowType'], 'label' => sprintf('Traces of %s in %s', $worst['shortName'], $failing[0]['service'])],
            );
        }
        if ($traced !== []) {
            $count = array_sum(array_map(static fn (array $section): int => $section['count'], $traced));
            $head = $status === Status::Ok
                ? sprintf('All clear: %d %s in ', $count, $count === 1 ? 'flow type' : 'flow types')
                : sprintf('No errors recorded in %d %s of ', $count, $count === 1 ? 'flow type' : 'flow types');
            $parts = [['text' => $head]];
            foreach ($traced as $index => $section) {
                if ($index > 0) {
                    $parts[] = ['text' => $index === count($traced) - 1 ? ' and ' : ', '];
                }
                $parts[] = ['text' => $section['service'], 'service' => $section['service']];
            }
            $parts[] = ['text' => $status === Status::Ok ? ', no errors recorded.' : '.'];

            return new self($sections, $status, $reason, $parts, $meta, null);
        }
        if ($waiting !== [] && $nothingYet === []) {
            return new self($sections, $status, $reason, [['text' => 'Waiting for the services to answer before Pulse can list their flow types.']], $meta, null);
        }
        if ($nothingYet !== []) {
            return new self($sections, $status === Status::Ok ? Status::Info : $status, $reason, [['text' => sprintf('Flow tracking is on in %s, but no flow has been tracked yet.', self::listOf($nothingYet))]], array_values(array_filter([$off === [] ? null : sprintf('flow tracking off in %s', self::listOf($off))])), null);
        }

        return new self($sections, Status::Unknown, sprintf('flow tracking is not enabled in %s', self::listOf($off)), [['text' => 'Pulse has no traces to show.']], [], null);
    }

    /**
     * @param list<array<string, mixed>> $sections
     * @return list<array{text: string, service?: string}>
     */
    private static function perService(string $head, array $sections): array
    {
        if (count($sections) === 1) {
            return [['text' => $head . ', all in '], ['text' => $sections[0]['service'], 'service' => $sections[0]['service']], ['text' => '.']];
        }
        $parts = [['text' => $head . ': ']];
        foreach ($sections as $index => $section) {
            if ($index > 0) {
                $parts[] = ['text' => ', '];
            }
            $parts[] = ['text' => sprintf('%d in %s', $section['failing'], $section['service']), 'service' => $section['service']];
        }
        $parts[] = ['text' => '.'];

        return $parts;
    }

    /**
     * @param array{shortName: string, p95Ms: int}|null $slowest
     */
    private static function slowestPart(?array $slowest): string
    {
        return $slowest === null ? '' : sprintf(' · slowest %s, 95%% within %d ms', $slowest['shortName'], $slowest['p95Ms']);
    }

    /**
     * @return array{string, string}
     */
    private static function timeOf(string $at, DateTimeImmutable $now): array
    {
        if ($at === '') {
            return ['at an unreported time', 'unreported'];
        }
        try {
            $time = (new DateTimeImmutable($at))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) {
            return [$at, $at];
        }
        $seconds = $now->getTimestamp() - $time->getTimestamp();
        $ago = match (true) {
            $seconds < 60 => 'just now',
            $seconds < 3600 => intdiv($seconds, 60) . ' min ago',
            $seconds < 86400 => intdiv($seconds, 3600) . ' h ago',
            default => intdiv($seconds, 86400) . ' d ago',
        };

        return [$time->format('Y-m-d H:i:s') . ' UTC', $ago];
    }

    private static function durationOf(int $seconds): string
    {
        return match (true) {
            $seconds > 0 && $seconds % 86400 === 0 => self::plural(intdiv($seconds, 86400), 'day'),
            $seconds > 0 && $seconds % 3600 === 0 => self::plural(intdiv($seconds, 3600), 'hour'),
            $seconds >= 60 => self::plural(intdiv($seconds, 60), 'minute'),
            default => self::plural($seconds, 'second'),
        };
    }

    private static function plural(int $count, string $noun): string
    {
        return $count . ' ' . $noun . ($count === 1 ? '' : 's');
    }

    /**
     * @param array<string> $names
     */
    private static function listOf(array $names): string
    {
        $names = array_values($names);
        if (count($names) <= 1) {
            return implode('', $names);
        }

        return implode(', ', array_slice($names, 0, -1)) . ' and ' . $names[count($names) - 1];
    }

    private static function shortName(string $name): string
    {
        $position = strrpos($name, '\\');

        return $position === false ? $name : substr($name, $position + 1);
    }
}
