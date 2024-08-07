<?php

namespace Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert;

use JMS\Serializer\Annotation as Serializer;

/**
 * licence Apache-2.0
 */
class PropertyWithAnnotationMetadataDefined
{
    /**
     * @Serializer\SerializedName("naming")
     * @Serializer\Type("string")
     */
    #[Serializer\SerializedName('naming')]
    #[Serializer\Type('string')]
    private $name;

    /**
     * ObjectWithAnnotationMetadataDefined constructor.
     * @param $name
     */
    public function __construct($name)
    {
        $this->name = $name;
    }
}
