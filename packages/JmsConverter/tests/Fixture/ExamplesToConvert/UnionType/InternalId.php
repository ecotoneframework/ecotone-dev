<?php

namespace Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert\UnionType;

/**
 * licence Apache-2.0
 */
final class InternalId
{
    public function __construct(private string $id)
    {
    }

    public function getId(): string
    {
        return $this->id;
    }
}
