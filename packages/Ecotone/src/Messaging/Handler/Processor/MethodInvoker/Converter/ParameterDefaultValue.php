<?php

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter;

class ParameterDefaultValue
{
    public function __construct(private mixed $value)
    {
    }

    public function getValue(): mixed
    {
        return $this->value;
    }
}
