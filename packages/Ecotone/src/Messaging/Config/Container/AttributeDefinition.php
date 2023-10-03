<?php

namespace Ecotone\Messaging\Config\Container;

class AttributeDefinition extends Definition
{
    public function instance(): object
    {
        return new $this->className(...$this->constructorArguments);
    }
}