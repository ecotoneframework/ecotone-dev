<?php

declare(strict_types=1);

namespace App\Projection;

use App\Ui\Status;

enum ProjectionState: string
{
    case UpToDate = 'up_to_date';
    case CatchingUp = 'catching_up';
    case Stalled = 'stalled';
    case Rebuilding = 'rebuilding';
    case Backfilling = 'backfilling';
    case NotInitialized = 'not_initialized';
    case WaitingForFirstEvent = 'waiting_for_first_event';
    case Prepared = 'prepared';
    case Failed = 'failed';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::UpToDate => 'Up to date',
            self::CatchingUp => 'Catching up',
            self::Stalled => 'Stalled',
            self::Rebuilding => 'Rebuilding',
            self::Backfilling => 'Backfilling',
            self::NotInitialized => 'Not initialized',
            self::WaitingForFirstEvent => 'Waiting for its first event',
            self::Prepared => 'Kicked off, waiting for its first run',
            self::Failed => 'Failed',
            self::Unknown => 'Unknown',
        };
    }

    public function status(): Status
    {
        return match ($this) {
            self::Failed => Status::Critical,
            self::Stalled, self::CatchingUp, self::NotInitialized => Status::Warning,
            self::Rebuilding, self::Backfilling, self::Prepared => Status::Info,
            self::Unknown => Status::Unknown,
            self::UpToDate, self::WaitingForFirstEvent => Status::Ok,
        };
    }

    public function urgency(): int
    {
        return match ($this) {
            self::Failed => 0,
            self::Stalled => 1,
            self::Unknown => 2,
            self::CatchingUp => 3,
            self::Rebuilding, self::Backfilling => 4,
            self::NotInitialized => 5,
            self::Prepared => 6,
            self::WaitingForFirstEvent => 7,
            self::UpToDate => 8,
        };
    }

    public function countLabel(): string
    {
        return match ($this) {
            self::UpToDate => 'up to date',
            self::CatchingUp => 'catching up',
            self::Stalled => 'stalled',
            self::Rebuilding => 'rebuilding',
            self::Backfilling => 'backfilling',
            self::NotInitialized => 'not initialized',
            self::WaitingForFirstEvent => 'waiting for a first event',
            self::Prepared => 'kicked off, not run yet',
            self::Failed => 'failed',
            self::Unknown => 'unknown',
        };
    }

    public function verdictPhrase(): string
    {
        return match ($this) {
            self::UpToDate => 'is up to date',
            self::CatchingUp => 'is catching up',
            self::Stalled => 'is stalled',
            self::Rebuilding => 'is being rebuilt',
            self::Backfilling => 'is being backfilled',
            self::NotInitialized => 'is not initialized',
            self::WaitingForFirstEvent => 'is waiting for its first event',
            self::Prepared => 'is kicked off and has not run yet',
            self::Failed => 'failed',
            self::Unknown => 'could not be read',
        };
    }
}
