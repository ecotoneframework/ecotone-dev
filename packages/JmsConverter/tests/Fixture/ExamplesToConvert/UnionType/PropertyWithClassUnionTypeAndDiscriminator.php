<?php

namespace Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert\UnionType;

use JMS\Serializer\Annotation\UnionDiscriminator;

/**
 * licence Apache-2.0
 */
final class PropertyWithClassUnionTypeAndDiscriminator
{
    public function __construct(
        #[UnionDiscriminator(field: 'type', map: ['internal' => InternalIdWithDiscriminator::class, 'external' => ExternalIdWithDiscriminator::class])]
        private InternalIdWithDiscriminator|ExternalIdWithDiscriminator $id
    ) {
    }

    public function getId(): InternalIdWithDiscriminator|ExternalIdWithDiscriminator
    {
        return $this->id;
    }
}
