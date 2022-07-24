<?php

namespace App\Infrastructure;

use App\ReadModel\TicketCounterProjection\TicketCounterState;
use Ecotone\Messaging\Attribute\Converter;

final class TicketCounterStateConverter
{
    #[Converter]
    public function from(TicketCounterState $ticketCounterState): array
    {
        return [
            "count" => $ticketCounterState->count
        ];
    }

    #[Converter]
    public function to(array $ticketCounterState): TicketCounterState
    {
        return new TicketCounterState($ticketCounterState['count'] ?? 0);
    }
}