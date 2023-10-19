<?php

namespace Ecotone\Laravel;

use Ecotone\Messaging\Config\Container\Compiler\ContainerImplementation;
use Ecotone\Messaging\Config\Container\ContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Illuminate\Foundation\Application;

class LaravelContainerImplementation implements ContainerImplementation
{
    public function __construct(private Application $app)
    {
    }

    public function process(ContainerBuilder $builder): void
    {
        foreach ($builder->getDefinitions() as $definition) {
            $this->app->bind($definition->getId(), function () use ($definition, $builder) {
                return $this->instantiateDefinition($definition, $builder);
            });
        }
    }

    private function instantiateDefinition(mixed $definition, ContainerBuilder $builder)
    {

    }
}