<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket;

use Ecotone\Messaging\Attribute\Converter;

class TicketEventConverter
{
    #[Converter]
    public function TicketAssignedToArray(TicketAssigned $event): array
    {
        return [
            'ticketId' => $event->ticketId,
        ];
    }

    #[Converter]
    public function arrayToTicketAssigned(array $data): TicketAssigned
    {
        return new TicketAssigned($data['ticketId']);
    }

    #[Converter]
    public function TicketUnassignedToArray(TicketUnassigned $event): array
    {
        return [
            'ticketId' => $event->ticketId,
        ];
    }

    #[Converter]
    public function arrayToTicketUnassigned(array $data): TicketUnassigned
    {
        return new TicketUnassigned($data['ticketId']);
    }

    #[Converter]
    public function TicketCreatedToArray(TicketCreated $event): array
    {
        return [
            'ticketId' => $event->ticketId,
        ];
    }

    #[Converter]
    public function arrayToTicketCreated(array $data): TicketCreated
    {
        return new TicketCreated($data['ticketId']);
    }


}
