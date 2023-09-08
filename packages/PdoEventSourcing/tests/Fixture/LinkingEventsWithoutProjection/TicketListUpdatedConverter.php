<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\LinkingEventsWithoutProjection;

use Ecotone\Messaging\Attribute\Converter;

final class TicketListUpdatedConverter
{
    #[Converter]
    public function toArray(TicketListUpdated $event): array
    {
        return [
            'ticketId' => $event->ticketId,
        ];
    }

    #[Converter]
    public function fromArray(array $event): TicketListUpdated
    {
        return new TicketListUpdated($event['ticketId']);
    }
}
