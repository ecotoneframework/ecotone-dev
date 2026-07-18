<?php

namespace Test\Ecotone\JMSConverter\Fixture\UnionIdentifierAggregateWithoutDiscriminator;

use Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert\UnionType\ExternalId;
use Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert\UnionType\InternalId;

/**
 * licence Apache-2.0
 */
final class TicketWasCreated
{
    public function __construct(public readonly InternalId|ExternalId $ticketId)
    {
    }
}
