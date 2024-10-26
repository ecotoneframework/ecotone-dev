<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Fixture\ScheduledHandler;

use Ecotone\Messaging\Attribute\Poller;
use Ecotone\Messaging\Attribute\Scheduled;

/**
 * licence Apache-2.0
 */
final class ScheduledHandler
{
    private int $counter = 0;

    #[Scheduled(endpointId: 'scheduled_handler')]
    #[Poller(fixedRateInMilliseconds: 1)]
    public function handle(): void
    {
        $this->counter += 1;
    }

    public function getCounter(): int
    {
        return $this->counter;
    }
}
