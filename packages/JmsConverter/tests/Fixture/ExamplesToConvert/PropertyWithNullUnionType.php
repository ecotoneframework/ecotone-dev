<?php

namespace Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert;

/**
 * licence Apache-2.0
 */
class PropertyWithNullUnionType
{
    private ?string $data;

    /**
     * PropertyWithNullUnionType constructor.
     * @param string|null $data
     */
    public function __construct(?string $data)
    {
        $this->data = $data;
    }
}
