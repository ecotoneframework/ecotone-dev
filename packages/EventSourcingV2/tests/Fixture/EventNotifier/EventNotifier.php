<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcingV2\Fixture\EventNotifier;

use Ecotone\Modelling\Attribute\EventHandler;

class EventNotifier
{
    private array $notifiedEvents = [];

    #[EventHandler]
    public function notify(object $events): void
    {
        $this->notifiedEvents[] = $events;
    }

    public function getNotifiedEvents(): array
    {
        return $this->notifiedEvents;
    }

    public function clear(): void
    {
        $this->notifiedEvents = [];
    }
}