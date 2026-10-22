<?php

declare(strict_types=1);

namespace App\Projection;

enum ProjectionState: string
{
    case UpToDate = 'up_to_date';
    case CatchingUp = 'catching_up';
    case Stalled = 'stalled';
    case Rebuilding = 'rebuilding';
    case Initializing = 'initializing';
    case Backfilling = 'backfilling';
    case NotInitialized = 'not_initialized';
    case Failed = 'failed';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::UpToDate => 'Up to date',
            self::CatchingUp => 'Catching up',
            self::Stalled => 'Stalled',
            self::Rebuilding => 'Rebuilding',
            self::Initializing => 'Initializing',
            self::Backfilling => 'Backfilling',
            self::NotInitialized => 'Not initialized',
            self::Failed => 'Failed',
            self::Unknown => 'Unknown',
        };
    }
}
