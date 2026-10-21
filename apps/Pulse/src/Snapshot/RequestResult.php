<?php

declare(strict_types=1);

namespace App\Snapshot;

enum RequestResult
{
    case NotNeeded;
    case Sent;
    case Failed;
}
