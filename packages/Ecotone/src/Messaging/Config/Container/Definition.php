<?php

namespace Ecotone\Messaging\Config\Container;

class Definition
{
    public function __construct(protected string $className, protected array $constructorArguments)
    {
    }

    public function getClassName(): string
    {
        return $this->className;
    }
}