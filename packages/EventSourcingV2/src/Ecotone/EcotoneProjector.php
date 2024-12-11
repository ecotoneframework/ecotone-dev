<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\EventSourcingV2\Ecotone;

use Ecotone\EventSourcingV2\EventStore\PersistedEvent;
use Ecotone\EventSourcingV2\EventStore\Projection\Projector;
use Ecotone\Messaging\Gateway\MessagingEntrypoint;

class EcotoneProjector implements Projector
{
    public function __construct(
        private MessagingEntrypoint $messagingEntrypoint,
        private array $eventToChannelMapping
    ) {
    }

    public function project(PersistedEvent $event): void
    {
        $route = $this->eventToChannelMapping[$event->event->type] ?? null;
        if ($route === null) {
            return;
        }
        $this->messagingEntrypoint->send(
            $event->event->payload,
            $route,
        );
    }
}