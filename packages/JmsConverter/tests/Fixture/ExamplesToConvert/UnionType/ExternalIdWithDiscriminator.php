<?php

namespace Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert\UnionType;

/**
 * licence Apache-2.0
 */
final class ExternalIdWithDiscriminator
{
    public string $type = 'external';

    public function __construct(private string $id)
    {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function __toString(): string
    {
        return $this->id;
    }
}
