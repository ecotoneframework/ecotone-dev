<?php

declare(strict_types=1);

namespace App\Snapshot;

enum ServiceStatus: string
{
    case Connected = 'connected';
    case Waiting = 'waiting';
    case Unreachable = 'unreachable';
    case ChannelMissing = 'channel_missing';
}
