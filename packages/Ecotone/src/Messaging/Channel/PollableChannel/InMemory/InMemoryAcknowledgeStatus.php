<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\PollableChannel\InMemory;

enum InMemoryAcknowledgeStatus: int
{
    case AWAITING = 0;
    case ACKED = 1;
    case RESENT = 2;
    case IGNORED = 3;
}