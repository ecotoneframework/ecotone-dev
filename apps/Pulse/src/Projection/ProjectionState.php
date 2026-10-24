<?php

declare(strict_types=1);

namespace App\Projection;

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
}
