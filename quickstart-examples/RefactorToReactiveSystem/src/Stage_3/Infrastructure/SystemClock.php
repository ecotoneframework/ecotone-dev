<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Stage_3\Infrastructure;

use App\ReactiveSystem\Stage_3\Domain\Clock;

final class SystemClock implements Clock
{
    public function getCurrentTime(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}