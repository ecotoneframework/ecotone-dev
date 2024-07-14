<?php

namespace Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert;

/**
 * licence Apache-2.0
 */
class TwoLevelNestedCollectionProperty
{
    /**
     * @var CollectionProperty[]
     */
    private array $collection;

    /**
     * TwoLevelNestedObjectProperty constructor.
     * @param CollectionProperty[] $collection
     */
    public function __construct($collection)
    {
        $this->collection = $collection;
    }
}
