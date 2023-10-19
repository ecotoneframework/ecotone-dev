<?php

namespace Ecotone\Laravel;

use Ecotone\Messaging\Config\Container\Compiler\ContainerImplementation;
use Ecotone\Messaging\Config\Container\ContainerBuilder;

class LaravelConfigurationHolder implements ContainerImplementation
{
    private array $definitions = [];
    private array $registeredCommands = [];

    public function process(ContainerBuilder $builder): void
    {
        $this->definitions = $builder->getDefinitions();
    }

    public function getDefinitions(): array
    {
        return $this->definitions;
    }

    public function getRegisteredCommands(): array
    {
        return $this->registeredCommands;
    }

    public function setRegisteredCommands(array $registeredCommands): void
    {
        $this->registeredCommands = $registeredCommands;
    }
}