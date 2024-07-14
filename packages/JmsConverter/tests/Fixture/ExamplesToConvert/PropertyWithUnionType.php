<?php

namespace Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert;

/**
 * licence Apache-2.0
 */
class PropertyWithUnionType
{
    /**
     * @var array|string[]
     */
    private array $data;

    /**
     * PropertyWithUnionType constructor.
     * @param array|string[] $data
     */
    public function __construct($data)
    {
        $this->data = $data;
    }
}
