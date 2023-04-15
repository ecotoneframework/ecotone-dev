<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Stage_2\Infrastructure;

use App\ReactiveSystem\Stage_2\Domain\Clock;

final class SystemClock implements Clock
{
    public function getCurrentTime(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}