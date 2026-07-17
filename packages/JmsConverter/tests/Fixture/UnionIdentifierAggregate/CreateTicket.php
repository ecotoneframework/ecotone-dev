<?php

namespace Test\Ecotone\JMSConverter\Fixture\UnionIdentifierAggregate;

use Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert\UnionType\ExternalIdWithDiscriminator;
use Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert\UnionType\InternalIdWithDiscriminator;

/**
 * licence Apache-2.0
 */
final class CreateTicket
{
    public function __construct(public readonly InternalIdWithDiscriminator|ExternalIdWithDiscriminator $ticketId)
    {
    }
}
