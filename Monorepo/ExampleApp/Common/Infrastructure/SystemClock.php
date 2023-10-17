<?php

declare(strict_types=1);

namespace Monorepo\ExampleApp\Common\Infrastructure;

use Monorepo\ExampleApp\Common\Domain\Clock;

final class SystemClock implements Clock
{
    public function getCurrentTime(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}