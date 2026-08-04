<?php

namespace Test\Ecotone\JMSConverter\Fixture\UnionIdentifierAggregate;

use JMS\Serializer\Annotation\UnionDiscriminator;
use Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert\UnionType\ExternalIdWithDiscriminator;
use Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert\UnionType\InternalIdWithDiscriminator;

/**
 * licence Apache-2.0
 */
final class TicketWasCreated
{
    public function __construct(
        #[UnionDiscriminator(field: 'type', map: ['internal' => InternalIdWithDiscriminator::class, 'external' => ExternalIdWithDiscriminator::class])]
        public readonly InternalIdWithDiscriminator|ExternalIdWithDiscriminator $ticketId
    ) {
    }
}
