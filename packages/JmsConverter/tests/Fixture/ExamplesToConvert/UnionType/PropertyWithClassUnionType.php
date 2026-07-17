<?php

namespace Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert\UnionType;

/**
 * licence Apache-2.0
 */
final class PropertyWithClassUnionType
{
    public function __construct(private InternalId|ExternalId $id)
    {
    }

    public function getId(): InternalId|ExternalId
    {
        return $this->id;
    }
}
