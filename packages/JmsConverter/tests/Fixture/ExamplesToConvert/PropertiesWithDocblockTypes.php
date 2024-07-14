<?php

namespace Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert;

/**
 * licence Apache-2.0
 */
class PropertiesWithDocblockTypes
{
    private string $name;
    private string $surname;

    /**
     * ObjectWithDocblockTypes constructor.
     * @param string $name
     * @param string $surname
     */
    public function __construct(string $name, string $surname)
    {
        $this->name = $name;
        $this->surname = $surname;
    }
}
