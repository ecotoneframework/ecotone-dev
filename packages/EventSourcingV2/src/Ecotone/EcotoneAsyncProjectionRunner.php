<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\EventSourcingV2\Ecotone;

use Ecotone\EventSourcingV2\EventStore\LogEventId;
use Ecotone\EventSourcingV2\EventStore\Subscription\PersistentSubscriptions;

class EcotoneAsyncProjectionRunner
{
    /**
     * @param array<string, EcotoneProjector> $ecotoneProjectors
     */
    public function __construct(
        private PersistentSubscriptions $eventStore,
        private array $ecotoneProjectors,
    ) {
    }

    public function run(string $subscription, ?LogEventId $until = null): void
    {
        $projector = $this->ecotoneProjectors[$subscription] ?? null;
        if ($projector === null) {
            return;
        }

        do {
            $eventPage = $this->eventStore->readFromSubscription($subscription);
            foreach ($eventPage->events as $event) {
                $projector->project($event);
            }
            $this->eventStore->ack($eventPage);
        } while ($until !== null && $eventPage->endPosition->isAfterOrEqual($until));
    }
}