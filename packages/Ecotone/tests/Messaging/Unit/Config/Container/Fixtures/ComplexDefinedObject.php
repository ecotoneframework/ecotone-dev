<?php

namespace Test\Ecotone\Messaging\Unit\Config\Container\Fixtures;

use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;

class ComplexDefinedObject implements DefinedObject
{
    public function __construct(public int $anInteger, public SimpleDefinedObject $anObject, public array $anArray)
    {
    }


    public function getDefinition(): Definition
    {
        return new Definition(self::class, [
            $this->anInteger,
            $this->anObject,
            $this->anArray
        ]);
    }
}