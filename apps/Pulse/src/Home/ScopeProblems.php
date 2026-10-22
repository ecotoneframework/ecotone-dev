<?php

declare(strict_types=1);

namespace App\Home;

use App\Projection\ProjectionOverview;
use App\Projection\ProjectionState;
use App\Projection\RebuildReport;
use App\Projection\RebuildTrackings;
use App\Settings\PulseSettings;
use App\Snapshot\ChannelDepth;
use App\Snapshot\ServiceSnapshot;
use App\Snapshot\ServiceStatus;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class ScopeProblems
{
    public function __construct(
        private PulseSettings $settings,
        private RebuildTrackings $trackings,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @return list<Problem>
     */
    public function of(ServiceSnapshot $scope, ?string $newestVersion): array
    {
        $problems = match ($scope->status) {
            ServiceStatus::Unreachable => [new Problem(Severity::Critical, 'unreachable', 'Not answering the snapshot request' . ($scope->requestFailure === null ? '' : ': ' . $scope->requestFailure), $this->hub($scope))],
            ServiceStatus::ChannelMissing => [new Problem(Severity::Critical, 'channel-missing', sprintf('Request channel "%s" does not exist on the broker', $scope->channel), $this->hub($scope))],
            ServiceStatus::Waiting => [],
            ServiceStatus::Connected => $scope->hasSnapshot() ? $this->measured($scope, $newestVersion) : [],
        };
        usort($problems, static fn (Problem $first, Problem $second): int => $second->severity->weight() <=> $first->severity->weight());

        return $problems;
    }

    /**
     * @return list<Problem>
     */
    private function measured(ServiceSnapshot $scope, ?string $newestVersion): array
    {
        $rebuilds = $this->hasProjections($scope) ? $this->trackings->of($scope) : RebuildReport::none();

        return [
            ...$this->versionDrift($scope, $newestVersion),
            ...$this->channels($scope),
            ...$this->projections($scope, $rebuilds),
            ...$this->rebuilds($scope, $rebuilds),
            ...$this->deadLetters($scope),
        ];
    }

    /**
     * @return list<Problem>
     */
    private function versionDrift(ServiceSnapshot $scope, ?string $newestVersion): array
    {
        $version = $scope->ecotoneVersion();
        if ($newestVersion === null || $version === null || version_compare($version, $newestVersion, '>=')) {
            return [];
        }

        return [new Problem(Severity::Warning, 'version-drift', sprintf('Runs Ecotone %s while another service runs %s', $version, $newestVersion), $this->urls->generate('services'))];
    }

    /**
     * @return list<Problem>
     */
    private function channels(ServiceSnapshot $scope): array
    {
        $problems = [];
        foreach (ChannelDepth::of($scope, $this->settings->queueThresholds) as $depth) {
            if (! in_array($depth->level, ['warning', 'critical'], true)) {
                continue;
            }
            $problems[] = new Problem(
                $depth->level === 'critical' ? Severity::Critical : Severity::Warning,
                'queue-' . $depth->level,
                sprintf('%s: %s ready (%s)', $depth->name, self::number((int) $depth->ready), $depth->level),
                $this->hub($scope) . '#channels',
            );
        }

        return $problems;
    }

    /**
     * @return list<Problem>
     */
    private function projections(ServiceSnapshot $scope, RebuildReport $rebuilds): array
    {
        $problems = [];
        foreach (ProjectionOverview::of($scope, $this->settings->projectionStalledAfterSeconds, $rebuilds) as $projection) {
            $href = $this->urls->generate('service_projections', ['service' => $scope->scope()]);
            $problem = match ($projection->state) {
                ProjectionState::Failed => new Problem(Severity::Critical, 'projection-failed', sprintf('%s failed: its channel has dead letters', $projection->name), $href),
                ProjectionState::Stalled => new Problem(Severity::Warning, 'projection-stalled', sprintf('%s stalled %d min', $projection->name, intdiv((int) $projection->changedSecondsAgo, 60)), $href),
                ProjectionState::CatchingUp => new Problem(Severity::Warning, 'projection-behind', sprintf('%s behind: %s', $projection->name, $projection->gap), $href),
                default => null,
            };
            if ($problem !== null) {
                $problems[] = $problem;
            }
        }

        return $problems;
    }

    /**
     * @return list<Problem>
     */
    private function rebuilds(ServiceSnapshot $scope, RebuildReport $report): array
    {
        $problems = [];
        $href = $this->urls->generate('service_projections', ['service' => $scope->scope()]);
        $seen = [];
        foreach ($report->rebuilds as $rebuild) {
            if (isset($seen[$rebuild->projection])) {
                continue;
            }
            $seen[$rebuild->projection] = true;
            if ($rebuild->isRunning()) {
                $problems[] = new Problem(Severity::Info, 'rebuild-running', sprintf('%s rebuilding%s', $rebuild->projection, $rebuild->elapsed() === null ? '' : ' for ' . $rebuild->elapsed()), $href);
            } elseif ($rebuild->state === 'failed') {
                $problems[] = new Problem(Severity::Warning, 'rebuild-failed', sprintf('%s rebuild failed in the last %d days', $rebuild->projection, $this->settings->trackingDays), $href);
            }
        }

        return $problems;
    }

    /**
     * @return list<Problem>
     */
    private function deadLetters(ServiceSnapshot $scope): array
    {
        $problems = [];
        if ($scope->deadLettersGrewBy > 0) {
            $problems[] = new Problem(Severity::Critical, 'dead-letters-growing', sprintf('Dead letters growing: %d new since the last snapshot', $scope->deadLettersGrewBy), $this->urls->generate('dead_letters', ['service' => $scope->scope()]));
        }
        foreach ($scope->snapshot['deadLetters']['groups'] ?? [] as $group) {
            if (! is_array($group)) {
                continue;
            }
            $count = (int) ($group['count'] ?? 0);
            $name = ($group['messageName'] ?? '') ?: '(unnamed)';
            $problems[] = new Problem(
                Severity::Warning,
                'dead-letters',
                sprintf('%d %s in %s', $count, $count === 1 ? 'failure' : 'failures', $name),
                $this->urls->generate('dead_letters', ['service' => $scope->scope(), 'failureId' => (string) ($group['failureId'] ?? '')]),
            );
        }

        return $problems;
    }

    private function hasProjections(ServiceSnapshot $scope): bool
    {
        return is_array($scope->snapshot['projections'] ?? null) && $scope->snapshot['projections'] !== [];
    }

    private function hub(ServiceSnapshot $scope): string
    {
        return $this->urls->generate('service_hub', array_filter(['service' => $scope->service, 'tenant' => $scope->tenant]));
    }

    private static function number(int $value): string
    {
        return number_format($value, 0, '', ' ');
    }
}
