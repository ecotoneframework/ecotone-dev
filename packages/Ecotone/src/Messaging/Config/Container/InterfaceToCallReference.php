<?php

namespace Ecotone\Messaging\Config\Container;

class InterfaceToCallReference extends Reference
{
    public function __construct(private string $className, private string $methodName)
    {
        parent::__construct('interfaceToCall-'.$className.'::'.$methodName);
    }

    public function getClassName(): string
    {
        return $this->className;
    }

    public function getMethodName(): string
    {
        return $this->methodName;
    }

}