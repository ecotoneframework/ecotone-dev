<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\Scheduled;

use Ecotone\Messaging\Attribute\Interceptor\Before;
use Ecotone\Modelling\Attribute\QueryHandler;

/**
 * licence Apache-2.0
 */
final class ScheduledMarkerInvocationCounter
{
    private int $count = 0;

    #[Before(pointcut: ScheduledMarkerAttribute::class)]
    public function increment(): void
    {
        $this->count++;
    }

    #[QueryHandler('scheduledMarker.count')]
    public function count(): int
    {
        return $this->count;
    }
}
