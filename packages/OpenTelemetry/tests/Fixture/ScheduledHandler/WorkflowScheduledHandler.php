<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Fixture\ScheduledHandler;

use Ecotone\Messaging\Attribute\InternalHandler;
use Ecotone\Messaging\Attribute\Poller;
use Ecotone\Messaging\Attribute\Scheduled;

/**
 * licence Apache-2.0
 */
final class WorkflowScheduledHandler
{
    private int $counter = 0;

    #[Scheduled(requestChannelName: 'add', endpointId: 'scheduled_handler')]
    #[Poller(fixedRateInMilliseconds: 1)]
    public function produce(): int
    {
        return 1;
    }

    #[Scheduled(requestChannelName: 'add', endpointId: 'scheduled_handler_without_result')]
    #[Poller(fixedRateInMilliseconds: 1)]
    public function produceNothing(): ?int
    {
        return null;
    }

    #[InternalHandler(inputChannelName: 'add')]
    public function add(): void
    {
        $this->counter += 1;
    }

    public function getCounter(): int
    {
        return $this->counter;
    }
}
