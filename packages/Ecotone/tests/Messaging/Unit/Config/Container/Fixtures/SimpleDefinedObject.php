<?php

namespace Test\Ecotone\Messaging\Unit\Config\Container\Fixtures;

use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;

class SimpleDefinedObject implements DefinedObject
{
    public function __construct(private int $anInteger = 1, private string $aString = "aString")
    {
    }

    public function getDefinition(): Definition
    {
        return new Definition(self::class, [
            $this->anInteger,
            $this->aString
        ]);
    }
}