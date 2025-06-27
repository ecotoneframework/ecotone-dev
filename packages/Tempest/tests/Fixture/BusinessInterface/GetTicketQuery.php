<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Fixture\BusinessInterface;

/**
 * licence Apache-2.0
 */
final readonly class GetTicketQuery
{
    public function __construct(
        public string $ticketId
    ) {
    }
}
