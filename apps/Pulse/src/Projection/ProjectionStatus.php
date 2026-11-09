<?php

declare(strict_types=1);

namespace App\Projection;

use App\Ui\Status;
use App\Ui\StatusView;

final readonly class ProjectionStatus
{
    /**
     * @param array{total: int, inspected: int, behind: int, atLeast: bool}|null $partitions
     */
    public function __construct(
        public string $name,
        public string $mode,
        public bool $partitioned,
        public ?string $initialization,
        public string $gap,
        public bool $behind,
        public ProjectionState $state,
        public ?int $changedSecondsAgo,
        public ?string $unavailable,
        public ?string $rebuildAsyncChannel,
        public ?string $backfillAsyncChannel,
        public ?Rebuild $latestRebuild,
        public bool $manualKickOff,
        public ?string $initializeCommand,
        public ?string $asyncChannel,
        public ?array $partitions,
    ) {
    }

    public function isSetUp(): bool
    {
        return $this->state !== ProjectionState::NotInitialized && $this->state !== ProjectionState::WaitingForFirstEvent;
    }

    public function canBeBackfilled(): bool
    {
        return $this->mode !== 'streaming' && $this->unavailable === null && $this->isSetUp();
    }

    public function canBeRebuilt(): bool
    {
        return $this->isSetUp();
    }

    public function status(): Status
    {
        return $this->state->status();
    }

    public function statusView(): StatusView
    {
        return StatusView::of($this->status(), $this->status()->needsReason() ? ($this->unavailable ?? 'the service did not report its position') : null);
    }

    public function isQuiet(): bool
    {
        return $this->status() === Status::Ok || $this->mode === 'streaming';
    }

    public function explanation(int $stalledAfterSeconds): string
    {
        return match ($this->state) {
            ProjectionState::Failed => sprintf('a message on its channel %s failed after its retries and is stored in dead letters.', $this->asyncChannel ?? 'of this projection'),
            ProjectionState::Stalled => sprintf('%s, and its position has not moved for %s.', $this->gap, self::spoken($this->changedSecondsAgo ?? $stalledAfterSeconds)),
            ProjectionState::CatchingUp => sprintf('%s; Pulse calls it stalled when its position does not move for %s.', $this->gap, self::spoken($stalledAfterSeconds)),
            ProjectionState::Rebuilding, ProjectionState::Backfilling => $this->runningExplanation(),
            ProjectionState::NotInitialized => sprintf('it projects nothing until it is initialized with %s.', $this->initializeCommand ?? 'ecotone:projection:init ' . $this->name),
            ProjectionState::Prepared => 'it is initialized but has not projected yet; Backfill projects the events stored so far.',
            ProjectionState::WaitingForFirstEvent => 'it sets itself up when its first event arrives.',
            ProjectionState::Unknown => sprintf('%s.', rtrim($this->unavailable ?? 'the service did not report its position', '.')),
            ProjectionState::UpToDate => $this->upToDateExplanation(),
        };
    }

    public function initializationLabel(): string
    {
        if ($this->partitioned) {
            return match ($this->initialization) {
                'initialized' => 'Initialized: every partition',
                'uninitialized' => 'Not every partition yet: Ecotone initializes each partition on its first event',
                default => 'Not reported by the service',
            };
        }

        return match ($this->initialization) {
            'initialized' => 'Initialized',
            'prepared' => 'Kicked off, not run yet',
            'uninitialized' => 'Not initialized yet',
            default => 'Not reported by the service',
        };
    }

    public function partitionsLabel(): ?string
    {
        if ($this->partitions === null) {
            return null;
        }
        $inspected = $this->partitions['atLeast'] ? sprintf(', the first %d inspected', $this->partitions['inspected']) : '';

        return sprintf('%d %s%s, %d of them behind', $this->partitions['total'], $this->partitions['total'] === 1 ? 'partition' : 'partitions', $inspected, $this->partitions['behind']);
    }

    public function scopeOfOperations(): string
    {
        if ($this->partitions === null) {
            return '';
        }

        return sprintf(' · %d %s', $this->partitions['total'], $this->partitions['total'] === 1 ? 'partition' : 'partitions');
    }

    public function backfillScope(): string
    {
        if ($this->partitions !== null) {
            return $this->scopeOfOperations();
        }

        return $this->behind ? ' · ' . $this->gap : ' · nothing behind';
    }

    public function changedAgo(): ?string
    {
        return $this->changedSecondsAgo === null ? null : self::spoken($this->changedSecondsAgo) . ' ago';
    }

    private function runningExplanation(): string
    {
        $operation = $this->latestRebuild;
        if ($operation === null) {
            return 'it is running.';
        }
        if ($this->partitioned && $operation->batchCount) {
            return sprintf('%d%% done, %d of %d batches.', $operation->percent(), $operation->batchesDone, $operation->batchCount);
        }

        return sprintf('running for %s.', $operation->elapsed() ?? '0s');
    }

    private function upToDateExplanation(): string
    {
        if ($this->partitions === null) {
            return 'no event is left to project.';
        }
        if ($this->initialization === 'uninitialized') {
            return 'no partition has an event left to project. Ecotone initializes each partition on its first event, so a partition without one yet is not initialized.';
        }

        return 'no partition has an event left to project.';
    }

    private static function spoken(int $seconds): string
    {
        return match (true) {
            $seconds < 60 => $seconds . 's',
            $seconds < 3600 => intdiv($seconds, 60) . 'm',
            default => intdiv($seconds, 3600) . 'h',
        };
    }
}
