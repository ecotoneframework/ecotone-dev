<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Part_2\Infrastructure;

use App\ReactiveSystem\Part_2\Domain\Clock;

final class SystemClock implements Clock
{
    public function getCurrentTime(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}