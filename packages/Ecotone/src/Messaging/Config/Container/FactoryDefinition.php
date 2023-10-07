<?php

namespace Ecotone\Messaging\Config\Container;

class FactoryDefinition
{
    public function __construct(private array $factory, private array $arguments = [])
    {
    }

    public function getFactory(): array
    {
        return $this->factory;
    }

    public function getArguments(): array
    {
        return $this->arguments;
    }
}