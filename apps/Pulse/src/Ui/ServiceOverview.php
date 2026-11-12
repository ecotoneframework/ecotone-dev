<?php

declare(strict_types=1);

namespace App\Ui;

use App\Home\Problem;
use App\Home\Tile;
use App\Projection\ProjectionOverview;
use App\Settings\PulseSettings;
use App\Snapshot\ChannelDepth;
use App\Snapshot\ServiceSnapshot;
use App\Snapshot\ServiceStatus;
use App\Trace\FlowTypeOverview;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Throwable;

final readonly class ServiceOverview
{
    public const PROBLEMS_SHOWN = 3;

    public function __construct(private PulseSettings $settings, private UrlGeneratorInterface $urls)
    {
    }

    /**
     * @param list<Tile> $tiles
     * @return list<ProblemCard>
     */
    public function problemCards(array $tiles): array
    {
        $cards = [];
        foreach ($tiles as $tile) {
            foreach ($tile->problems as $problem) {
                $cards[] = $problem->kind === 'dead-letters' ? $this->deadLetterCard($tile->scope, $problem) : $this->card($tile->scope, $problem);
            }
        }
        usort($cards, static fn (ProblemCard $first, ProblemCard $second): int => $second->severity->weight() <=> $first->severity->weight());

        return $cards;
    }

    /**
     * @param list<ProblemCard> $cards
     * @param list<SummaryTile> $tiles
     * @param list<ChannelDepth> $channels
     * @param list<ServiceSnapshot> $tenantRows
     */
    public function verdict(ServiceSnapshot $service, ?ServiceSnapshot $focus, array $cards, array $tiles, array $channels, array $tenantRows): Verdict
    {
        $scope = $focus?->tenant === null ? $service->service : sprintf('%s (tenant %s)', $service->service, $focus->tenant);
        $status = Status::worstOf(
            ...array_map(static fn (ProblemCard $card): Status => Status::of($card->severity), $cards),
            ...array_map(static fn (SummaryTile $tile): Status => $tile->status, $tiles),
        );
        if (! $service->hasSnapshot()) {
            return match ($service->status) {
                ServiceStatus::Unreachable => new Verdict(Status::Critical, sprintf('%s did not answer the snapshot request.', $service->service), false),
                ServiceStatus::ChannelMissing => new Verdict(Status::Critical, sprintf('%s cannot answer: its request channel %s does not exist on the broker.', $service->service, $service->channel), false),
                default => new Verdict(Status::Info, sprintf('Waiting for %s to answer for the first time.', $service->service), false),
            };
        }
        $base = match ($service->status) {
            ServiceStatus::Connected => sprintf('%s is connected', $scope),
            ServiceStatus::Waiting => sprintf('Pulse is waiting for a fresh answer from %s and shows its last one', $scope),
            ServiceStatus::Unreachable => sprintf('%s did not answer the latest snapshot request', $scope),
            ServiceStatus::ChannelMissing => sprintf('%s cannot answer: its request channel %s does not exist on the broker', $scope, $service->channel),
        };
        if ($cards !== []) {
            return new Verdict(
                $status,
                sprintf('%s; %d %s %s attention%s.', $base, count($cards), count($cards) === 1 ? 'problem' : 'problems', count($cards) === 1 ? 'needs' : 'need', $focus === null && $tenantRows !== [] ? ' across its tenants' : ''),
                false,
            );
        }
        if ($service->status !== ServiceStatus::Connected) {
            return new Verdict(Status::Info, $base . '.', false);
        }

        return new Verdict($status, 'All clear: ' . implode(', ', $this->checked($focus, $channels, $tenantRows)) . '.', true);
    }

    /**
     * @param list<ChannelDepth> $channels
     * @param list<string> $perTenantChannels
     * @return list<SummaryTile>
     */
    public function tiles(ServiceSnapshot $service, ?ServiceSnapshot $focus, array $channels, array $perTenantChannels): array
    {
        $tiles = [$this->channelsTile($service, $focus, $channels, $perTenantChannels)];
        if ($focus !== null) {
            $tiles[] = $this->deadLettersTile($service, $focus);
            $tiles[] = $this->projectionsTile($focus);
            $tiles[] = $this->tracesTile($focus);
        }

        return $tiles;
    }

    /**
     * @return list<SummaryTile>
     */
    public function scopeTiles(ServiceSnapshot $scope): array
    {
        return [
            $this->channelsTile($scope, $scope, $this->channelsOf($scope), []),
            $this->deadLettersTile($scope, $scope),
            $this->projectionsTile($scope),
        ];
    }

    /**
     * @return list<ChannelDepth>
     */
    public function channelsOf(ServiceSnapshot $scope): array
    {
        return ChannelDepth::of($scope, $this->settings->queueThresholds, $this->settings->channelStalledAfterSeconds);
    }

    /**
     * @return array{total: int, behind: int, stalled: int, failed: int}
     */
    public function projectionCounts(ServiceSnapshot $scope): array
    {
        return ProjectionOverview::counts(ProjectionOverview::of($scope, $this->settings->projectionStalledAfterSeconds));
    }

    /**
     * @param list<ChannelDepth> $channels
     */
    public function channelsSummary(array $channels): string
    {
        return sprintf('%d %s · %s', count($channels), count($channels) === 1 ? 'channel' : 'channels', ChannelDepth::stateOf($channels));
    }

    /**
     * @param list<ChannelDepth> $channels
     * @param list<string> $perTenantChannels
     */
    public function unmeasured(array $channels, array $perTenantChannels): ?string
    {
        $unmeasured = [];
        foreach ($channels as $depth) {
            $reason = $depth->depthShortReason(in_array($depth->name, $perTenantChannels, true));
            if ($reason !== null) {
                $unmeasured[] = $depth->name . ': ' . $reason;
            }
        }

        return $unmeasured === [] ? null : implode('; ', $unmeasured);
    }

    /**
     * @return array<string, string>
     */
    public function tabRoutes(ServiceSnapshot $service, ?string $tenant): array
    {
        $own = array_filter(['service' => $service->service, 'tenant' => $tenant]);
        $scope = $tenant === null ? $service->service : $service->service . '@' . $tenant;

        return [
            'overview' => $this->urls->generate('service_hub', $own),
            'channels' => $this->urls->generate('service_channels', $own),
            'failures' => $this->urls->generate('dead_letters', ['service' => $scope]),
            'projections' => $this->urls->generate('service_projections', ['service' => $scope]),
            'gathering' => $this->urls->generate('service_gathering', ['service' => $scope]),
            'traces' => $this->urls->generate('traces', ['service' => $scope]),
            'configuration' => $this->urls->generate('service_configuration', $own),
        ];
    }

    /**
     * @param list<ChannelDepth> $channels
     * @param list<string> $perTenantChannels
     */
    private function channelsTile(ServiceSnapshot $service, ?ServiceSnapshot $focus, array $channels, array $perTenantChannels): SummaryTile
    {
        $unmeasured = $this->unmeasured($channels, $perTenantChannels);

        return new SummaryTile(
            kind: 'channels',
            label: 'Channels',
            count: (string) count($channels),
            noun: count($channels) === 1 ? 'channel' : 'channels',
            state: $channels === [] ? 'none reported' : ChannelDepth::stateOf($channels),
            status: ChannelDepth::statusOf($channels),
            detail: $unmeasured,
            href: $this->urls->generate('service_channels', array_filter(['service' => $service->service, 'tenant' => $focus?->tenant])),
            concept: 'channel',
        );
    }

    private function deadLettersTile(ServiceSnapshot $service, ServiceSnapshot $focus): SummaryTile
    {
        $total = $focus->deadLetterTotal();
        if ($total === null || ($focus->snapshot['deadLetter']['enabled'] ?? null) === false) {
            return new SummaryTile('dead-letters', 'Failed messages', 'Off', 'dead letter storage', 'not configured', Status::Ok, 'Enable it with DbalConfiguration::createWithDefaults()->withDeadLetter(true)', $this->urls->generate('service_configuration', array_filter(['service' => $service->service, 'tenant' => $focus->tenant])), 'dead-letter');
        }
        $groups = (int) $focus->failureCount();

        return new SummaryTile(
            kind: 'dead-letters',
            label: 'Failed messages',
            count: number_format($total),
            noun: $total === 1 ? 'failed message' : 'failed messages',
            state: $total === 0 ? 'none stored' : sprintf('in %d failure %s', $groups, $groups === 1 ? 'group' : 'groups'),
            status: $total > 0 ? Status::Warning : Status::Ok,
            detail: null,
            href: $this->urls->generate('dead_letters', ['service' => $focus->scope()]),
            concept: 'dead-letter',
        );
    }

    private function projectionsTile(ServiceSnapshot $focus): SummaryTile
    {
        $counts = ProjectionOverview::counts(ProjectionOverview::of($focus, $this->settings->projectionStalledAfterSeconds));
        $parts = array_filter(['behind' => $counts['behind'], 'stalled' => $counts['stalled'], 'failed' => $counts['failed']]);

        return new SummaryTile(
            kind: 'projections',
            label: 'Projections',
            count: (string) $counts['total'],
            noun: $counts['total'] === 1 ? 'projection' : 'projections',
            state: match (true) {
                $counts['total'] === 0 => 'none in this service',
                $parts === [] => $counts['total'] === 1 ? 'up to date' : 'all up to date',
                default => implode(' · ', array_map(static fn (string $state, int $count): string => $count . ' ' . $state, array_keys($parts), $parts)),
            },
            status: match (true) {
                $counts['failed'] > 0 => Status::Critical,
                $parts !== [] => Status::Warning,
                default => Status::Ok,
            },
            detail: null,
            href: $this->urls->generate('service_projections', ['service' => $focus->scope()]),
            concept: 'projection',
        );
    }

    private function tracesTile(ServiceSnapshot $focus): SummaryTile
    {
        $overview = FlowTypeOverview::of([$focus])[0];
        $href = $this->urls->generate('traces', ['service' => $focus->scope()]);
        if (! $overview['tracing']) {
            return new SummaryTile('traces', 'Traces', 'Off', 'flow tracking', 'not enabled', Status::Ok, 'Enable it with FlowTrackingConfiguration::createWithDefaults()', $href, 'trace');
        }
        $flowTypes = $overview['flowTypes'];
        $failing = count(array_filter($flowTypes, static fn (array $flowType): bool => (int) ($flowType['runsWithErrors'] ?? 0) > 0));
        $slowest = null;
        foreach ($flowTypes as $flowType) {
            if ($slowest === null || (int) ($flowType['p95Ms'] ?? 0) > (int) ($slowest['p95Ms'] ?? 0)) {
                $slowest = $flowType;
            }
        }

        return new SummaryTile(
            kind: 'traces',
            label: 'Traces',
            count: (string) count($flowTypes),
            noun: count($flowTypes) === 1 ? 'flow type' : 'flow types',
            state: match (true) {
                $flowTypes === [] => 'none tracked yet',
                $failing > 0 => sprintf('%d with errors recorded', $failing),
                default => 'no errors recorded',
            },
            status: $failing > 0 ? Status::Info : Status::Ok,
            detail: $slowest === null ? null : sprintf('slowest: %s, 95%% within %d ms', self::shortName((string) ($slowest['flowType'] ?? '')), (int) ($slowest['p95Ms'] ?? 0)),
            href: $href,
            concept: 'flow-type',
        );
    }

    /**
     * @param list<ChannelDepth> $channels
     * @param list<ServiceSnapshot> $tenantRows
     * @return list<string>
     */
    private function checked(?ServiceSnapshot $focus, array $channels, array $tenantRows): array
    {
        $checked = [sprintf('%d %s', count($channels), count($channels) === 1 ? 'channel' : 'channels')];
        if ($focus === null) {
            return [sprintf('%d %s answered', count($tenantRows), count($tenantRows) === 1 ? 'tenant' : 'tenants'), ...$checked];
        }
        $total = $focus->deadLetterTotal();
        $checked[] = $total === null || ($focus->snapshot['deadLetter']['enabled'] ?? null) === false ? 'dead letters not stored' : 'no failed messages';
        $counts = ProjectionOverview::counts(ProjectionOverview::of($focus, $this->settings->projectionStalledAfterSeconds));
        $checked[] = match ($counts['total']) {
            0 => 'no projections',
            1 => '1 projection, not behind',
            default => sprintf('%d projections, none behind', $counts['total']),
        };

        return $checked;
    }

    private function card(ServiceSnapshot $scope, Problem $problem): ProblemCard
    {
        [$label, $href, $concept] = match (true) {
            in_array($problem->kind, ['unreachable', 'channel-missing'], true) => ['Diagnose', $problem->href . '#diagnose', 'request-channel'],
            $problem->kind === 'dead-letters-growing' => ['Open the failed messages', $problem->href, 'dead-letter'],
            in_array($problem->kind, ['channel-not-draining', 'queue-warning', 'queue-critical'], true) => ['Open the channels', $problem->href, 'channel'],
            str_starts_with($problem->kind, 'projection-'), str_starts_with($problem->kind, 'rebuild-'), str_starts_with($problem->kind, 'backfill-') => ['Open the projections', $problem->href, 'projection'],
            in_array($problem->kind, ['table-missing', 'channel-not-set-up', 'setup-unknown', 'section-failed'], true) => ['Open the setup', $problem->href, null],
            $problem->kind === 'version-drift' => ['Compare the versions', $problem->href, null],
            default => ['Open', $problem->href, null],
        };

        return new ProblemCard($problem->severity, $problem->kind, [['text' => $problem->text]], [...$this->whereOf($scope), ...($problem->subject === null ? [] : [['label' => $problem->subject]])], null, $label, $href, $concept, null);
    }

    private function deadLetterCard(ServiceSnapshot $scope, Problem $problem): ProblemCard
    {
        $group = [];
        foreach ($scope->snapshot['deadLetters']['groups'] ?? [] as $candidate) {
            if (is_array($candidate) && (string) ($candidate['failureId'] ?? '') === $problem->subject) {
                $group = $candidate;
            }
        }
        $count = (int) ($group['count'] ?? 0);
        $name = (string) (($group['messageName'] ?? '') ?: '(unnamed)');
        $short = self::shortName($name);
        $only = null;
        if ($count === 1) {
            foreach ($scope->snapshot['deadLetters']['recent'] ?? [] as $entry) {
                if (is_array($entry) && (string) ($entry['failureId'] ?? '') === $problem->subject && is_string($entry['messageId'] ?? null)) {
                    $only = $entry['messageId'];
                }
            }
        }
        $file = is_string($group['exceptionFile'] ?? null) ? $group['exceptionFile'] : null;
        $line = is_int($group['exceptionLine'] ?? null) ? $group['exceptionLine'] : null;
        $message = trim((string) ($group['exceptionMessage'] ?? ''));
        $evidence = trim(implode(' · ', array_filter([
            trim(self::shortName((string) ($group['exceptionClass'] ?? '')) . ($message === '' ? '' : ': ' . self::cutAtWord($message, 140)), ': '),
            self::lastFailed($group['lastFailedAt'] ?? null),
        ])));

        return new ProblemCard(
            severity: $problem->severity,
            kind: $problem->kind,
            text: [
                ['text' => $count . ' '],
                $short === $name ? ['text' => $short] : ['text' => $short, 'title' => $name],
                ['text' => $count === 1 ? ' message failed after its retries and is kept until you replay or delete it.' : ' messages failed after their retries and are kept until you replay or delete them.'],
            ],
            where: [...$this->whereOf($scope), ...($file === null ? [] : [['label' => basename($file) . ($line === null ? '' : ':' . $line), 'title' => $file . ($line === null ? '' : ':' . $line)]])],
            evidence: $evidence === '' ? null : $evidence,
            actionLabel: $count === 1 ? 'Open the failed message' : sprintf('Open the %d failed messages', $count),
            actionHref: $only === null ? $problem->href : $this->urls->generate('dead_letter', ['service' => $scope->scope(), 'messageId' => $only]),
            concept: 'dead-letter',
            failureId: $problem->subject,
        );
    }

    /**
     * @return list<array{label: string}>
     */
    private function whereOf(ServiceSnapshot $scope): array
    {
        return [['label' => $scope->service], ...($scope->tenant === null ? [] : [['label' => $scope->tenant]])];
    }

    private static function cutAtWord(string $text, int $limit): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }
        $cut = mb_substr($text, 0, $limit - 1);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space === false || $space < $limit / 2 ? $cut : mb_substr($cut, 0, $space), ' ,.;:') . '…';
    }

    private static function shortName(string $name): string
    {
        $position = strrpos($name, '\\');

        return $position === false ? $name : substr($name, $position + 1);
    }

    private static function lastFailed(mixed $at): ?string
    {
        if (! is_string($at) || $at === '') {
            return null;
        }
        try {
            return 'last failed ' . (new DateTimeImmutable($at))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i') . ' UTC';
        } catch (Throwable) {
            return null;
        }
    }
}
