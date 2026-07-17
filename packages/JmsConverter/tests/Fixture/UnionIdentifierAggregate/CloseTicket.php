<?php

namespace Test\Ecotone\JMSConverter\Fixture\UnionIdentifierAggregate;

/**
 * licence Apache-2.0
 */
final class CloseTicket
{
    public function __construct(public readonly string $ticketId)
    {
    }
}
