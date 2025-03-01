<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Fixture\Calendar;

final readonly class CloseTicket
{
    public function __construct(public string $ticketId)
    {
    }
}
