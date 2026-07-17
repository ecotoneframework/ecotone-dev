<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\Fixture\IdentifierMapping\UnionType;

/**
 * licence Apache-2.0
 */
final class TicketWasCreated
{
    public function __construct(public readonly InternalId|ExternalId $ticketId)
    {
    }
}
