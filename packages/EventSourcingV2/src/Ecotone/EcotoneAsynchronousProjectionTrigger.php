<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\EventSourcingV2\Ecotone;

use Ecotone\EventSourcingV2\EventStore\LogEventId;
use Ecotone\EventSourcingV2\EventStore\PersistedEvent;
use Ecotone\EventSourcingV2\EventStore\Projection\FlushableProjector;
use Ecotone\Messaging\Gateway\MessagingEntrypoint;

class EcotoneAsynchronousProjectionTrigger implements FlushableProjector
{
    /**
     * @var array<string, LogEventId>
     */
    private array $projectionsToTrigger = [];

    /**
     * @param array<string, array<string> $eventToProjectionsMapping
     */
    public function __construct(
        private MessagingEntrypoint $messagingEntrypoint,
        private array $eventToProjectionsMapping,
    ) {
    }

    public function project(PersistedEvent $event): void
    {
        $projections = $this->eventToProjectionsMapping[$event->event->type] ?? null;
        if (! $projections) {
            return;
        }
        foreach ($projections as $projection) {
            $this->projectionsToTrigger[$projection] = $event->logEventId;
        }
    }

    public function flush(): void
    {
        if (empty($this->projectionsToTrigger)) {
            return;
        }
        foreach ($this->projectionsToTrigger as $projection => $logEventId) {
            $this->messagingEntrypoint->send(
                new EcotoneAsynchronousProjectionRunnerCommand($projection, $logEventId),
                EcotoneAsynchronousProjectionRunner::PROJECTION_RUNNER_CHANNEL,
            );
        }
        $this->projectionsToTrigger = [];
    }
}