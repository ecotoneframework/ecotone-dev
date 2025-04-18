<?php

declare(strict_types=1);

namespace Ecotone\SymfonyBundle\Compatibility;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * A compiler pass that ensures compatibility with different Symfony versions
 * 
 * licence Apache-2.0
 */
class ContainerCompatibilityPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // Set a parameter that can be used in the container dumper
        $container->setParameter('ecotone.container.compatibility.has_get_parameter_return_type', ContainerCompatibility::hasGetParameterReturnType());
        $container->setParameter('ecotone.container.compatibility.get_parameter_return_type', ContainerCompatibility::getParameterReturnTypeAsString());
    }
}
