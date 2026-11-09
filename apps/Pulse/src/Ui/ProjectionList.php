<?php

declare(strict_types=1);

namespace App\Ui;

use App\Projection\ProjectionState;
use App\Projection\ProjectionStatus;
use App\Projection\Rebuild;
use App\Projection\RebuildReport;
use App\Snapshot\ServiceSnapshot;

final readonly class ProjectionList
{
    /**
     * @param list<ProjectionStatus> $attention
     * @param list<ProjectionStatus> $quiet
     */
    private function __construct(
        public ServiceSnapshot $snapshot,
        public array $attention,
        public array $quiet,
        public RebuildReport $rebuilds,
        public int $stalledAfterSeconds,
        public int $trackingDays,
    ) {
    }

    /**
     * @param list<ProjectionStatus> $projections
     */
    public static function of(ServiceSnapshot $snapshot, array $projections, RebuildReport $rebuilds, int $stalledAfterSeconds, int $trackingDays): self
    {
        usort($projections, static fn (ProjectionStatus $left, ProjectionStatus $right): int => [$left->state->urgency(), $left->name] <=> [$right->state->urgency(), $right->name]);

        return new self(
            $snapshot,
            array_values(array_filter($projections, static fn (ProjectionStatus $projection): bool => ! $projection->isQuiet())),
            array_values(array_filter($projections, static fn (ProjectionStatus $projection): bool => $projection->isQuiet())),
            $rebuilds,
            $stalledAfterSeconds,
            $trackingDays,
        );
    }

    /**
     * @return list<ProjectionStatus>
     */
    public function projections(): array
    {
        return [...$this->attention, ...$this->quiet];
    }

    public function anchorOf(ProjectionStatus $projection): string
    {
        return self::anchorFor($this->snapshot, $projection->name);
    }

    public static function anchorFor(ServiceSnapshot $snapshot, string $projection): string
    {
        return 'projection-' . $snapshot->scope() . '-' . $projection;
    }

    public function operationsAnchor(): string
    {
        return 'operations-' . $this->snapshot->scope();
    }

    public function attentionStatus(): Status
    {
        return Status::worstOf(...array_map(static fn (ProjectionStatus $projection): Status => $projection->status(), $this->attention));
    }

    public function attentionSummary(): string
    {
        return count($this->attention) . ' · ' . implode(' · ', self::stateCounts($this->attention));
    }

    public function quietSummary(): string
    {
        $count = count($this->quiet);
        $noun = $count === 1 ? 'projection' : 'projections';
        $states = self::stateCounts($this->quiet);
        if (count($states) === 1 && array_key_first($states) === ProjectionState::UpToDate->value) {
            return sprintf('%d %s · %s', $count, $noun, $count === 1 ? 'up to date' : 'all up to date');
        }

        return sprintf('%d %s · %s', $count, $noun, implode(' · ', $states));
    }

    public function failedOperation(): ?Rebuild
    {
        $seen = [];
        foreach ($this->rebuilds->rebuilds as $rebuild) {
            if (isset($seen[$rebuild->projection])) {
                continue;
            }
            $seen[$rebuild->projection] = true;
            if ($rebuild->state === 'failed') {
                return $rebuild;
            }
        }

        return null;
    }

    public function operationsStatus(): StatusView
    {
        return match (true) {
            $this->rebuilds->refusal !== null => StatusView::of(Status::Unknown, 'the service refused to list its tracked operations'),
            $this->rebuilds->notAnswering => StatusView::of(Status::Unknown, 'the service did not answer the question for its tracked operations'),
            $this->failedOperation() !== null => StatusView::of(Status::Warning),
            $this->rebuilds->anyRunning() => StatusView::of(Status::Info),
            default => StatusView::of(Status::Ok),
        };
    }

    public function operationsOpen(): bool
    {
        return $this->operationsStatus()->status !== Status::Ok;
    }

    public function operationsSummary(): string
    {
        $rebuilds = $this->rebuilds->rebuilds;
        if ($this->rebuilds->refusal !== null || $this->rebuilds->notAnswering) {
            return 'not available';
        }
        if ($rebuilds === []) {
            return sprintf('none in the last %d %s', $this->trackingDays, $this->trackingDays === 1 ? 'day' : 'days');
        }
        $running = count(array_filter($rebuilds, static fn (Rebuild $rebuild): bool => $rebuild->state === 'running'));
        $failed = count(array_filter($rebuilds, static fn (Rebuild $rebuild): bool => $rebuild->state === 'failed'));
        $parts = array_filter([$running > 0 ? $running . ' running' : null, $failed > 0 ? $failed . ' failed' : null]);

        return count($rebuilds) . ' · ' . ($parts === [] ? 'all done' : implode(' · ', $parts));
    }

    /**
     * @param list<self> $lists
     */
    public static function verdictOf(string $service, array $lists): Verdict
    {
        $prefixed = count($lists) > 1;
        foreach ($lists as $list) {
            if (! $list->snapshot->hasSnapshot()) {
                return new Verdict(Status::Unknown, sprintf('Waiting for the first answer of %s.', $list->snapshot->scope()), false);
            }
        }

        $attention = [];
        $total = 0;
        foreach ($lists as $list) {
            $total += count($list->projections());
            foreach ($list->attention as $projection) {
                $attention[] = [$list, $projection];
            }
        }
        usort($attention, static fn (array $left, array $right): int => $left[1]->state->urgency() <=> $right[1]->state->urgency());

        if ($total === 0) {
            return new Verdict(Status::Ok, sprintf('%s reports no projections.', $service), true);
        }

        if ($attention === []) {
            foreach ($lists as $list) {
                $failed = $list->failedOperation();
                if ($failed !== null) {
                    return new Verdict(Status::Warning, sprintf('%d %s up to date, but the last %s of %s%s failed.', $total, $total === 1 ? 'projection is' : 'projections are', $failed->noun(), $prefixed ? $list->snapshot->tenant . ': ' : '', $failed->projection), false);
                }
            }
            $quiet = array_merge(...array_map(static fn (self $list): array => $list->quiet, $lists));
            $states = self::stateCounts($quiet);
            $summary = count($states) === 1 && array_key_first($states) === ProjectionState::UpToDate->value
                ? ($total === 1 ? 'up to date' : 'all up to date')
                : implode(', ', $states);

            return new Verdict(Status::Ok, sprintf('All clear: %d %s, %s.', $total, $total === 1 ? 'projection' : 'projections', $summary), true);
        }

        $status = Status::worstOf(...array_map(static fn (array $entry): Status => $entry[1]->status(), $attention));
        if (count($attention) === 1) {
            [$list, $projection] = $attention[0];

            return new Verdict($status, sprintf('%s%s %s: %s', $prefixed ? $list->snapshot->tenant . ': ' : '', $projection->name, $projection->state->verdictPhrase(), $projection->explanation($list->stalledAfterSeconds)), false);
        }

        $needing = array_filter($attention, static fn (array $entry): bool => $entry[1]->status() !== Status::Info);

        return new Verdict($status, sprintf(
            '%d of %d projections %s: %s.',
            count($attention),
            $total,
            $needing === [] ? 'are busy' : 'need attention',
            implode(', ', self::stateCounts(array_map(static fn (array $entry): ProjectionStatus => $entry[1], $attention))),
        ), false);
    }

    /**
     * @param list<self> $lists
     */
    public static function verdictReasonOf(array $lists): ?string
    {
        foreach ($lists as $list) {
            if (! $list->snapshot->hasSnapshot()) {
                return sprintf('Pulse has not received a snapshot of %s yet.', $list->snapshot->scope());
            }
        }
        foreach ($lists as $list) {
            foreach ($list->attention as $projection) {
                if ($projection->status() === Status::Unknown) {
                    return sprintf('%s did not report the position of %s: %s', $list->snapshot->scope(), $projection->name, $projection->unavailable ?? 'no reason given');
                }
            }
        }

        return null;
    }

    /**
     * @param list<self> $lists
     * @return array{label: string, href: string}|null
     */
    public static function verdictActionOf(array $lists): ?array
    {
        $first = null;
        foreach ($lists as $list) {
            foreach ($list->attention as $projection) {
                if ($first === null || $projection->state->urgency() < $first[1]->state->urgency()) {
                    $first = [$list, $projection];
                }
            }
        }
        if ($first !== null) {
            return ['label' => 'Open ' . $first[1]->name, 'href' => '#' . $first[0]->anchorOf($first[1])];
        }
        foreach ($lists as $list) {
            if ($list->failedOperation() !== null) {
                return ['label' => 'See the recent operations', 'href' => '#' . $list->operationsAnchor()];
            }
        }

        return null;
    }

    /**
     * @param list<ProjectionStatus> $projections
     * @return array<string, string>
     */
    private static function stateCounts(array $projections): array
    {
        $counts = [];
        foreach ($projections as $projection) {
            $counts[$projection->state->value] = ($counts[$projection->state->value] ?? 0) + 1;
        }
        $states = array_keys($counts);
        usort($states, static fn (string $left, string $right): int => ProjectionState::from($left)->urgency() <=> ProjectionState::from($right)->urgency());

        $labels = [];
        foreach ($states as $state) {
            $labels[$state] = $counts[$state] . ' ' . ProjectionState::from($state)->countLabel();
        }

        return $labels;
    }
}
