<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\LinkingEventsWithoutProjection;

use Ecotone\EventSourcing\EventStore;
use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Modelling\Event;

final class NotificationService
{
    public const STREAM_NAME = 'stream';

    private array $publishedEvents = [];

    #[QueryHandler('get.notifications')]
    public function getNotifications(#[Reference] EventStore $eventStore): ?string
    {
        if (! $eventStore->hasStream(self::STREAM_NAME)) {
            return null;
        }

        /** @var Event[] $events */
        $events = $eventStore->load(self::STREAM_NAME);

        return end($events)->getPayload()->ticketId;
    }

    #[EventHandler]
    public function subscribeToProjectionEvent(TicketListUpdated $event): void
    {
        $this->publishedEvents[] = $event;
    }

    #[QueryHandler('get.published_events')]
    public function lastPublishedEvent(): array
    {
        return $this->publishedEvents;
    }
}
