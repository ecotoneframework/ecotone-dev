<?php

namespace Ecotone\SymfonyBundle\DependencyInjection\Compiler;

use Ecotone\Messaging\ConfigurationVariableService;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * licence Apache-2.0
 */
class SymfonyConfigurationVariableService implements ConfigurationVariableService
{
    public function __construct(private ContainerInterface $container)
    {
    }

    public function getByName(string $name)
    {
        $value = $this->container->getParameter($name);
        if ($this->container instanceof ContainerBuilder
            && is_string($value)
            && str_starts_with($value, '%')
            && str_ends_with($value, '%')
        ) {
            $value = $this->container->resolveEnvPlaceholders($value, true);
        }

        return $value;
    }

    public function hasName(string $name): bool
    {
        return $this->container->hasParameter($name);
    }
}
