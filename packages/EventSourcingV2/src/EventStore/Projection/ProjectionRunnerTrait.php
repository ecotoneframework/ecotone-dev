<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\EventSourcingV2\EventStore\Projection;

use Ecotone\EventSourcingV2\EventStore\PersistedEvent;

trait ProjectionRunnerTrait
{
    /**
     * @var iterable<PersistedEvent> $events
     */
    public function projectEvents(Projector $projector, iterable $events): void
    {
        foreach ($events as $event) {
            $projector->project($event);
        }
        if ($projector instanceof FlushableProjector) {
            $projector->flush();
        }
    }
}